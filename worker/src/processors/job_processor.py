import os
import io
import json
import time
import zipfile
import tempfile
import logging
from xml.etree import ElementTree as ET

import numpy as np
import psycopg2
import boto3
from PIL import Image
import trimesh
import trimesh.transformations as tft
from scipy.ndimage import gaussian_filter, binary_dilation, binary_closing
from dotenv import load_dotenv

load_dotenv()

log = logging.getLogger(__name__)

SUPPORTED_FORMATS = {"stl", "obj", "ply", "glb", "gltf", "3mf"}


class JobProcessor:
    def __init__(self) -> None:
        self.conn = self._connect_with_retry()
        self.s3 = boto3.client(
            "s3",
            endpoint_url=os.getenv("RUSTFS_ENDPOINT", "http://rustfs:9000"),
            aws_access_key_id=os.getenv("RUSTFS_ACCESS_KEY"),
            aws_secret_access_key=os.getenv("RUSTFS_SECRET_KEY"),
        )
        self.bucket_logos  = os.getenv("RUSTFS_BUCKET_LOGOS",  "logos")
        self.bucket_models = os.getenv("RUSTFS_BUCKET_MODELS", "models")
        self.bucket_output = os.getenv("RUSTFS_BUCKET_OUTPUT", "output")
        self._ensure_buckets()

    # ── Bucket init ───────────────────────────────────────────────────────────

    def _ensure_buckets(self) -> None:
        for bucket in (self.bucket_logos, self.bucket_models, self.bucket_output):
            try:
                self.s3.head_bucket(Bucket=bucket)
            except Exception:
                try:
                    self.s3.create_bucket(Bucket=bucket)
                    log.info(f"Bucket '{bucket}' created.")
                except Exception as e:
                    log.warning(f"Could not create bucket '{bucket}': {e}")

    # ── DB connection ─────────────────────────────────────────────────────────

    def _connect_with_retry(self, retries: int = 10, delay: float = 3.0):
        for attempt in range(1, retries + 1):
            try:
                conn = psycopg2.connect(
                    host=os.getenv("DB_HOST", "postgres"),
                    port=int(os.getenv("DB_PORT", 5432)),
                    dbname=os.getenv("DB_NAME", "hek3d"),
                    user=os.getenv("DB_USER", "hek3d_user"),
                    password=os.getenv("DB_PASSWORD", ""),
                    connect_timeout=10,
                    keepalives=1, keepalives_idle=30,
                    keepalives_interval=10, keepalives_count=5,
                )
                log.info("PostgreSQL connected.")
                return conn
            except psycopg2.OperationalError as exc:
                log.warning(f"DB not ready (attempt {attempt}/{retries}): {exc}")
                if attempt == retries:
                    raise
                time.sleep(delay)

    def _reconnect(self) -> None:
        log.warning("Reconnecting to PostgreSQL...")
        try:
            self.conn.close()
        except Exception:
            pass
        self.conn = self._connect_with_retry()

    def _ensure_conn(self) -> None:
        if self.conn.closed:
            self._reconnect()
            return
        try:
            self.conn.cursor().execute("SELECT 1")
        except (psycopg2.OperationalError, psycopg2.InterfaceError):
            self._reconnect()

    # ── Job fetching ──────────────────────────────────────────────────────────

    def fetch_pending_job(self) -> dict | None:
        self._ensure_conn()
        try:
            return self._fetch()
        except (psycopg2.OperationalError, psycopg2.InterfaceError):
            self._reconnect()
            return self._fetch()

    def _fetch(self) -> dict | None:
        with self.conn.cursor() as cur:
            cur.execute(
                """
                UPDATE jobs SET status = 'processing', updated_at = NOW()
                WHERE id = (
                    SELECT id FROM jobs WHERE status = 'pending'
                    ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED
                )
                RETURNING id, logo_key, model_key, placement, model_color, logo_color,
                          plate_index, filter_objects, layers, print_settings
                """
            )
            self.conn.commit()
            row = cur.fetchone()
            if row:
                placement = row[3]
                if isinstance(placement, str):
                    placement = json.loads(placement)
                layers = row[8]
                if isinstance(layers, str):
                    layers = json.loads(layers)
                ps = row[9]
                if isinstance(ps, str):
                    ps = json.loads(ps)
                return {
                    "id":             str(row[0]),
                    "logo_key":       row[1],
                    "model_key":      row[2],
                    "placement":      placement,
                    "model_color":    row[4] or "#4a9eff",
                    "logo_color":     row[5] or "#ffffff",
                    "plate_index":    row[6],
                    "filter_objects": row[7],
                    "layers":         layers,
                    "print_settings": ps,
                }
        return None

    # ── Processing ────────────────────────────────────────────────────────────

    def process(self, job: dict) -> None:
        try:
            ext = job["model_key"].rsplit(".", 1)[-1].lower()
            if ext not in SUPPORTED_FORMATS:
                raise ValueError(f"Unsupported model format: {ext}")

            with tempfile.TemporaryDirectory() as tmp:
                model_path = os.path.join(tmp, f"model.{ext}")
                out_path   = os.path.join(tmp, "output.3mf")

                self.s3.download_file(self.bucket_models, job["model_key"], model_path)

                # Only download the legacy logo if we're not using multi-layer mode
                logo_path = None
                if job.get("logo_key") and not job.get("layers"):
                    logo_path = os.path.join(tmp, "logo.png")
                    self.s3.download_file(self.bucket_logos, job["logo_key"], logo_path)

                self._build_export(
                    logo_path, model_path, out_path, ext,
                    placement      = job.get("placement"),
                    model_color    = job.get("model_color", "#4a9eff"),
                    logo_color     = job.get("logo_color",  "#ffffff"),
                    plate_index    = job.get("plate_index"),
                    filter_objects = job.get("filter_objects"),
                    layers         = job.get("layers"),
                    print_settings = job.get("print_settings"),
                )

                output_key = f"output/{job['id']}.3mf"
                self.s3.upload_file(out_path, self.bucket_output, output_key)
                self._update_job(job["id"], "done", output_key=output_key)

        except Exception as exc:
            log.error(f"Job {job['id']} failed: {exc}", exc_info=True)
            self._update_job(job["id"], "error", error=str(exc))

    # ── Mesh loading ──────────────────────────────────────────────────────────

    def _load_mesh(
        self, path: str, ext: str,
        plate_index: int | None = None,
        filter_objects: str | None = None,
    ) -> trimesh.Trimesh:
        if ext == "3mf":
            return self._load_3mf(path, plate_index, filter_objects)
        obj = trimesh.load(path, force="mesh")
        if isinstance(obj, trimesh.Scene):
            meshes = [g for g in obj.geometry.values()
                      if isinstance(g, trimesh.Trimesh)]
            obj = trimesh.util.concatenate(meshes) if meshes else trimesh.Trimesh()
        return obj

    def _load_3mf(
        self, path: str,
        plate_index: int | None = None,
        filter_objects: str | None = None,
    ) -> trimesh.Trimesh:
        ns = "http://schemas.microsoft.com/3dmanufacturing/core/2015/02"

        # Determine seed IDs, then expand through component references
        seed_ids: set[str] | None = None
        if filter_objects:
            seed_ids = set(json.loads(filter_objects))
        elif plate_index is not None:
            seed_ids = self._get_plate_object_ids(path, plate_index)

        allowed_ids = self._resolve_component_ids(path, seed_ids) if seed_ids is not None else None

        meshes = []
        with zipfile.ZipFile(path) as z:
            for mf in [n for n in z.namelist() if n.endswith(".model")]:
                root = ET.fromstring(z.read(mf))
                for obj_el in (root.findall(f".//{{{ns}}}object") or
                               root.findall(".//object")):
                    obj_type = obj_el.get("type", "model")
                    if obj_type in ("support", "solidsupport"):
                        continue
                    if allowed_ids is not None and obj_el.get("id") not in allowed_ids:
                        continue
                    mesh_el = (obj_el.find(f"{{{ns}}}mesh") or obj_el.find("mesh"))
                    if mesh_el is None:
                        continue
                    v_el = mesh_el.find(f"{{{ns}}}vertices") or mesh_el.find("vertices")
                    t_el = mesh_el.find(f"{{{ns}}}triangles") or mesh_el.find("triangles")
                    if v_el is None or t_el is None:
                        continue
                    verts = [[float(v.get("x", 0)), float(v.get("y", 0)), float(v.get("z", 0))]
                             for v in (v_el.findall(f"{{{ns}}}vertex") or v_el.findall("vertex"))]
                    faces = [[int(t.get("v1")), int(t.get("v2")), int(t.get("v3"))]
                             for t in (t_el.findall(f"{{{ns}}}triangle") or t_el.findall("triangle"))]
                    if verts and faces:
                        meshes.append(trimesh.Trimesh(vertices=verts, faces=faces))
        if not meshes:
            raise ValueError("Keine Geometrie in 3MF-Datei gefunden")
        return trimesh.util.concatenate(meshes) if len(meshes) > 1 else meshes[0]

    def _resolve_component_ids(self, path: str, seed_ids: set[str]) -> set[str]:
        """BFS-expand seed IDs by following <component objectid="…"/> references."""
        ns = "http://schemas.microsoft.com/3dmanufacturing/core/2015/02"
        component_refs: dict[str, list[str]] = {}
        with zipfile.ZipFile(path) as z:
            for mf in [n for n in z.namelist() if n.endswith(".model")]:
                root = ET.fromstring(z.read(mf))
                for obj_el in (root.findall(f".//{{{ns}}}object") or
                               root.findall(".//object")):
                    obj_id = obj_el.get("id")
                    if not obj_id:
                        continue
                    refs = [
                        c.get("objectid")
                        for c in (obj_el.findall(f".//{{{ns}}}component") or
                                  obj_el.findall(".//component"))
                        if c.get("objectid")
                    ]
                    if refs:
                        component_refs[obj_id] = refs

        expanded: set[str] = set(seed_ids)
        queue = list(seed_ids)
        while queue:
            curr = queue.pop()
            for ref in component_refs.get(curr, []):
                if ref not in expanded:
                    expanded.add(ref)
                    queue.append(ref)
        return expanded

    def _get_plate_object_ids(self, path: str, plate_index: int) -> set[str] | None:
        """Return the set of object IDs belonging to the given BambuStudio plate."""
        with zipfile.ZipFile(path) as z:
            if "Metadata/model_settings.config" not in z.namelist():
                return None
            root = ET.fromstring(z.read("Metadata/model_settings.config"))
            for plate_el in root.findall("plate"):
                plater_id = None
                for m in plate_el.findall("metadata"):
                    if m.get("key") == "plater_id":
                        try:
                            plater_id = int(m.get("value", 0))
                        except ValueError:
                            pass
                if plater_id == plate_index:
                    return {o.get("id") for o in plate_el.findall("object") if o.get("id")}
        return None

    # ── Export ────────────────────────────────────────────────────────────────

    def _build_export(
        self,
        logo_path: str | None,
        model_path: str,
        out_path: str,
        ext: str,
        placement: dict | None,
        model_color: str,
        logo_color: str,
        plate_index: int | None = None,
        filter_objects: str | None = None,
        layers: list | None = None,
        print_settings: dict | None = None,
    ) -> None:
        ps         = print_settings or {}
        mesh       = self._load_mesh(model_path, ext, plate_index, filter_objects)
        self._apply_print_direction(mesh, ps.get("print_direction", "none"))
        detail_fix = bool(ps.get("detail_fix", False))
        logo_fills = []  # list of (trimesh.Trimesh, color_hex)

        if layers:
            for i, layer in enumerate(layers):
                layer_placement = layer.get("placement")
                layer_color     = layer.get("color", "#ffffff")
                layer_key       = layer.get("key")

                if not layer_key or not layer_placement or not layer_placement.get("position"):
                    log.info(f"Layer {i}: skipped (no key or placement)")
                    continue

                layer_logo_path = None
                try:
                    fd, layer_logo_path = tempfile.mkstemp(suffix=".png")
                    os.close(fd)
                    self.s3.download_file(self.bucket_logos, layer_key, layer_logo_path)

                    real_p = self._placement_to_mesh_space(mesh, layer_placement)
                    log.info(f"Layer {i}: pos={[round(v,2) for v in real_p['position']]}, "
                             f"size={real_p['size']:.2f}, color={layer_color}")

                    fill = self._raycast_logo_onto_surface(layer_logo_path, mesh, real_p, detail_fix)
                    if fill is not None:
                        log.info(f"Layer {i}: {len(fill.vertices)} verts, {len(fill.faces)} faces")
                        logo_fills.append((fill, layer_color))
                    else:
                        log.warning(f"Layer {i}: fill mesh empty – check placement/logo alpha")
                finally:
                    if layer_logo_path and os.path.exists(layer_logo_path):
                        os.unlink(layer_logo_path)

        elif logo_path and placement and placement.get("position"):
            real_p = self._placement_to_mesh_space(mesh, placement)
            log.info(f"Placement mm: pos={[round(v,2) for v in real_p['position']]}, "
                     f"size={real_p['size']:.2f}, normal={[round(v,3) for v in real_p['normal']]}")

            fill = self._raycast_logo_onto_surface(logo_path, mesh, real_p, detail_fix)
            if fill is not None:
                log.info(f"Logo fill: {len(fill.vertices)} verts, {len(fill.faces)} faces")
                logo_fills.append((fill, logo_color))
            else:
                log.warning("Logo fill mesh is empty – check placement coordinates and logo alpha")

        # ── Print-ready additions ──────────────────────────────────────────────
        support_mesh = self._generate_supports(
            mesh,
            mode            = ps.get("support_mode",  "auto"),
            angle_threshold = float(ps.get("support_angle", 45)),
        )
        brim_mesh = self._generate_brim(
            mesh,
            brim_width   = float(ps.get("brim_width",   5.0)),
            layer_height = float(ps.get("layer_height", 0.2)),
        )

        self._write_3mf(out_path, mesh, logo_fills, model_color,
                        support_mesh=support_mesh, brim_mesh=brim_mesh,
                        layer_height=float(ps.get("layer_height", 0.2)))

    # ── Print direction rotation ──────────────────────────────────────────────

    _PRINT_DIRECTION_ROTATIONS = {
        "flip_z": (np.pi,      [1, 0, 0]),
        "x_pos":  (-np.pi/2,   [0, 1, 0]),
        "x_neg":  ( np.pi/2,   [0, 1, 0]),
        "y_pos":  (-np.pi/2,   [1, 0, 0]),
        "y_neg":  ( np.pi/2,   [1, 0, 0]),
    }

    def _apply_print_direction(self, mesh: trimesh.Trimesh, direction: str) -> None:
        """Rotate the mesh in-place around its bounding-box centre.
        The viewer applies the same rotation so viewer-space placements remain valid.
        """
        if not direction or direction == "none":
            return
        spec = self._PRINT_DIRECTION_ROTATIONS.get(direction)
        if spec is None:
            log.warning(f"Unknown print_direction '{direction}', ignored.")
            return
        angle, axis = spec
        center = mesh.bounding_box.centroid
        R = tft.rotation_matrix(angle, axis, point=center)
        mesh.apply_transform(R)
        log.info(f"Print direction '{direction}': rotated {np.degrees(angle):.0f}° around {axis}")

    # ── Manifold repair ───────────────────────────────────────────────────────

    @staticmethod
    def _repair_to_manifold(mesh: trimesh.Trimesh) -> trimesh.Trimesh:
        """Make the logo-inlay mesh watertight using manifold3d (fixes Bambu error 9140)."""
        mesh.merge_vertices(digits_vertex=4)
        try:
            import manifold3d as m3d
            m = m3d.Manifold(
                mesh=m3d.Mesh(
                    vert_properties=mesh.vertices.astype(np.float32),
                    tri_verts=mesh.faces.astype(np.uint32),
                )
            )
            out = m.to_mesh()
            if len(out.tri_verts) == 0:
                raise ValueError("manifold3d returned empty mesh — input was non-manifold")
            verts = np.array(out.vert_properties, dtype=float)
            if verts.ndim == 2 and verts.shape[1] > 3:
                verts = verts[:, :3]
            return trimesh.Trimesh(
                vertices=verts,
                faces=np.array(out.tri_verts, dtype=int),
                process=False,
            )
        except Exception as exc:
            log.warning(f"manifold3d repair failed ({exc}), using trimesh fallback")
            trimesh.repair.fix_winding(mesh)
            trimesh.repair.fix_normals(mesh)
            trimesh.repair.fill_holes(mesh)
            return mesh

    # ── Coordinate transform ──────────────────────────────────────────────────

    def _placement_to_mesh_space(self, mesh: trimesh.Trimesh, placement: dict) -> dict:
        """
        The viewer modifies vertices directly: world = (original - center) * scale
        Inverse:  original = world / scale + center

        The exact center and scale are saved in placement['viewer_transform'].
        If missing (old jobs), we fall back to estimating from the mesh bounds.
        """
        vt = placement.get("viewer_transform")

        if vt:
            center = np.array(vt["center"], dtype=float)
            scale  = float(vt["scale"])           # = 3 / maxDim_original
        else:
            log.warning("No viewer_transform in placement — estimating from mesh bounds")
            box     = mesh.bounding_box
            center  = box.centroid
            max_dim = float(np.max(box.extents))
            scale   = 3.0 / max_dim if max_dim > 0 else 1.0

        pos_world = np.array(placement["position"], dtype=float)
        pos_mesh  = pos_world / scale + center      # exact inverse

        size_world = float(placement.get("size", 0.3))
        size_mesh  = size_world / scale             # distances scale the same way

        log.info(f"Transform: center={np.round(center,2).tolist()}, scale={scale:.6f}")
        log.info(f"Pos world→mesh: {np.round(pos_world,4).tolist()} → {np.round(pos_mesh,2).tolist()}")
        log.info(f"Size world→mesh: {size_world:.4f} → {size_mesh:.2f}")

        return {
            **placement,
            "position": pos_mesh.tolist(),
            "size":     size_mesh,
        }

    # ── 3MF writer ────────────────────────────────────────────────────────────

    @staticmethod
    def _mesh_xml(mesh: trimesh.Trimesh, obj_id: int, name: str,
                  obj_type: str = "model") -> str:
        verts = mesh.vertices
        faces = mesh.faces
        v_lines = ''.join(
            f'<vertex x="{v[0]:.4f}" y="{v[1]:.4f}" z="{v[2]:.4f}"/>'
            for v in verts
        )
        t_lines = ''.join(
            f'<triangle v1="{t[0]}" v2="{t[1]}" v3="{t[2]}"/>'
            for t in faces
        )
        return (
            f'<object id="{obj_id}" name="{name}" type="{obj_type}">'
            f'<mesh><vertices>{v_lines}</vertices>'
            f'<triangles>{t_lines}</triangles></mesh></object>'
        )

    def _write_3mf(
        self,
        path: str,
        model_mesh: trimesh.Trimesh,
        logo_fills: list,
        model_color: str,
        support_mesh: "trimesh.Trimesh | None" = None,
        brim_mesh:    "trimesh.Trimesh | None" = None,
        layer_height: float = 0.2,
    ) -> None:
        ns = 'http://schemas.microsoft.com/3dmanufacturing/core/2015/02'

        obj_id    = 1
        resources = self._mesh_xml(model_mesh, obj_id, f"Grundkoerper - Filament 1 [{model_color}]")
        build     = f'<item objectid="{obj_id}"/>'

        for fill_mesh, fill_color in (logo_fills or []):
            if fill_mesh is not None and len(fill_mesh.faces) > 0:
                obj_id   += 1
                fill_idx  = obj_id - 1  # 1-based fill index
                resources += self._mesh_xml(
                    fill_mesh, obj_id,
                    f"Logo-Einlage {fill_idx} - Filament {obj_id} [{fill_color}]"
                )
                build += f'<item objectid="{obj_id}"/>'

        if support_mesh is not None and len(support_mesh.faces) > 0:
            obj_id   += 1
            resources += self._mesh_xml(support_mesh, obj_id, "Stuetzen - Filament 1")
            build     += f'<item objectid="{obj_id}"/>'

        if brim_mesh is not None and len(brim_mesh.faces) > 0:
            obj_id   += 1
            resources += self._mesh_xml(brim_mesh, obj_id,
                                        f"Brim - Filament 1 [{model_color}]")
            build     += f'<item objectid="{obj_id}"/>'

        model_xml = (
            '<?xml version="1.0" encoding="UTF-8"?>'
            f'<model unit="millimeter" xml:lang="en-US" xmlns="{ns}">'
            f'<metadata name="Application">HEK-3D-Worker</metadata>'
            f'<metadata name="Description">Modell: {model_color} | Layer: {layer_height} mm</metadata>'
            f'<resources>{resources}</resources>'
            f'<build>{build}</build>'
            '</model>'
        )

        content_types = (
            '<?xml version="1.0" encoding="UTF-8"?>'
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            '<Default Extension="model" ContentType="application/vnd.ms-package.3dmanufacturing-3dmodel+xml"/>'
            '</Types>'
        )
        rels = (
            '<?xml version="1.0" encoding="UTF-8"?>'
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            '<Relationship Target="/3D/3dmodel.model" Id="rel0" '
            'Type="http://schemas.microsoft.com/3dmanufacturing/2013/01/3dmodel"/>'
            '</Relationships>'
        )

        with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as zf:
            zf.writestr("[Content_Types].xml", content_types)
            zf.writestr("_rels/.rels",         rels)
            zf.writestr("3D/3dmodel.model",    model_xml)

    # ── Logo surface projection (raycasting) ─────────────────────────────────

    def _raycast_logo_onto_surface(
        self, logo_path: str, mesh: trimesh.Trimesh, placement: dict,
        detail_fix: bool = False,
    ) -> "trimesh.Trimesh | None":
        """
        Projects the logo onto the mesh surface and extrudes it INTO the body as a
        closed solid inlay (negative volume) for multi-colour 3D printing.
        """
        logo   = Image.open(logo_path).convert("RGBA")
        aspect = logo.width / logo.height

        pos      = np.array(placement["position"], dtype=float)
        normal   = np.array(placement["normal"],   dtype=float)
        normal  /= np.linalg.norm(normal)
        size     = float(placement.get("size",     10.0))
        rotation = float(placement.get("rotation", 0.0))

        up = np.array([0., 1., 0.])
        if abs(np.dot(normal, up)) > 0.9:
            up = np.array([1., 0., 0.])
        tangent   = np.cross(up, normal);  tangent  /= np.linalg.norm(tangent)
        bitangent = np.cross(normal, tangent)
        cos_r, sin_r = np.cos(rotation), np.sin(rotation)
        t_vec =  cos_r * tangent  + sin_r * bitangent
        b_vec = -sin_r * tangent  + cos_r * bitangent

        # target ≤0.1 mm per cell (2× safety margin for a 0.2 mm nozzle)
        RES   = min(512, max(256, int(size / 0.1)))
        DEPTH = max(0.3, size * 0.05)

        # ── Wall-thickness guard ──────────────────────────────────────────────
        # Cast a ray inward from the placement centre along -normal.
        # If the far wall is closer than DEPTH, cap DEPTH to 80 % of wall thickness
        # so the inlay never punches through the opposite side.
        _probe_origin = (pos - normal * 0.1).reshape(1, 3)
        _probe_dir    = (-normal).reshape(1, 3)
        _probe_locs, _, _ = trimesh.ray.ray_triangle.RayMeshIntersector(mesh).intersects_location(
            _probe_origin, _probe_dir, multiple_hits=False
        )
        if len(_probe_locs) > 0:
            _wall_dist = float(np.linalg.norm(_probe_locs[0] - pos))
            if _wall_dist < DEPTH + 0.1:
                DEPTH = max(0.1, _wall_dist * 0.75)
                log.info(f"Wall-thickness cap: depth reduced to {DEPTH:.2f} mm "
                         f"(wall = {_wall_dist:.2f} mm)")

        # For bottom-facing surfaces the outward protrusion would go BELOW the model
        # floor.  After the slicer places the model on the print bed (Z=0), that cap
        # is clipped, so only stray side-wall fragments appear.  Clamp ABOVE to 0
        # (flush with the surface) whenever the protrusion would exit below the mesh.
        ABOVE = 0.05
        _probe_z = float((pos + normal * ABOVE)[2])
        if _probe_z < float(mesh.bounds[0][2]) + 0.05:
            ABOVE = 0.0

        # 1. Rasterize at 4× resolution, Gaussian-blur alpha, then downsample.
        #    This gives a smooth anti-aliased boundary so the mesh outline matches
        #    the actual logo curve rather than a pixel staircase.
        OVER = 4
        logo_big = logo.resize((RES * OVER, RES * OVER), Image.LANCZOS)
        alpha_big = np.array(logo_big)[:, :, 3].astype(np.float32)
        alpha_big = gaussian_filter(alpha_big, sigma=OVER * 1.2)   # smooth sub-pixel edges
        # Downsample back to working resolution
        alpha_ds = np.array(
            Image.fromarray(np.clip(alpha_big, 0, 255).astype(np.uint8))
                 .resize((RES, RES), Image.LANCZOS)
        )
        rows, cols = np.where(alpha_ds > 64)   # lower threshold captures anti-aliased fringe
        if len(rows) == 0:
            return None
        filled_cells: set[tuple[int, int]] = set(zip(cols.tolist(), rows.tolist()))  # (ix, iy)

        if detail_fix:
            # Morphological closing: fills enclosed voids narrower than one nozzle diameter
            # (≈ 0.4 mm) so the slicer doesn't skip thin logo details.
            # closing = dilate then erode → outer boundary stays roughly unchanged.
            cell_mm  = size / RES
            morph_r  = max(1, int(np.ceil(0.4 / cell_mm / 2)))
            alpha_mask = np.zeros((RES, RES), dtype=bool)
            for (cx, cy) in filled_cells:
                if 0 <= cy < RES and 0 <= cx < RES:
                    alpha_mask[cy, cx] = True
            alpha_mask = binary_closing(alpha_mask, iterations=morph_r)
            rows, cols = np.where(alpha_mask)
            filled_cells = set(zip(cols.tolist(), rows.tolist()))

        # 2. Unique corners
        needed: set[tuple[int, int]] = set()
        for (ix, iy) in filled_cells:
            needed.update([(ix, iy), (ix+1, iy), (ix, iy+1), (ix+1, iy+1)])
        needed_list = sorted(needed)

        # 3. Build all ray origins in one numpy pass, then batch-cast
        bbox_diag  = float(np.linalg.norm(mesh.bounding_box.extents))
        ray_offset = normal * (bbox_diag + size)

        n          = len(needed_list)
        plane_pts  = np.empty((n, 3), dtype=float)
        for k, (ix, iy) in enumerate(needed_list):
            lx = (ix / RES - 0.5) * size * aspect
            ly = (0.5 - iy / RES) * size
            plane_pts[k] = pos + lx * t_vec + ly * b_vec

        origins = plane_pts + ray_offset                        # (n, 3)
        dirs    = np.tile(-normal, (n, 1)).astype(float)        # (n, 3)

        intersector = trimesh.ray.ray_triangle.RayMeshIntersector(mesh)
        all_locs, all_ray_ids, _ = intersector.intersects_location(
            origins, dirs, multiple_hits=True
        )

        # Per ray: keep hit closest to placement centre
        surface_hits: dict[int, np.ndarray] = {}
        if len(all_locs):
            dists = np.linalg.norm(all_locs - pos, axis=1)
            for sort_idx in np.argsort(dists):
                rid = int(all_ray_ids[sort_idx])
                if rid not in surface_hits:
                    surface_hits[rid] = all_locs[sort_idx]

        # 4. Two vertex layers: top (above) and bottom (into body)
        vertices: list[np.ndarray] = []
        top_verts: dict[tuple[int, int], int] = {}
        bot_verts: dict[tuple[int, int], int] = {}
        for k, key in enumerate(needed_list):
            surf_pt = surface_hits.get(k, plane_pts[k])
            top_verts[key] = len(vertices);  vertices.append(surf_pt + normal * ABOVE)
            bot_verts[key] = len(vertices);  vertices.append(surf_pt - normal * DEPTH)

        faces: list[list[int]] = []

        # 5. Top cap  — outward normal = +normal
        # cross(c11-c00, c10-c00) = cross(t-b, t) = +normal  ✓
        for (ix, iy) in filled_cells:
            c00 = top_verts.get((ix,   iy));  c10 = top_verts.get((ix+1, iy))
            c01 = top_verts.get((ix,   iy+1)); c11 = top_verts.get((ix+1, iy+1))
            if c00 is not None and c10 is not None and c11 is not None:
                faces.append([c00, c11, c10])
            if c00 is not None and c11 is not None and c01 is not None:
                faces.append([c00, c01, c11])

        # 6. Bottom cap — outward normal = -normal
        # cross(c10-c00, c11-c00) = cross(t, t-b) = -normal  ✓
        for (ix, iy) in filled_cells:
            c00 = bot_verts.get((ix,   iy));  c10 = bot_verts.get((ix+1, iy))
            c01 = bot_verts.get((ix,   iy+1)); c11 = bot_verts.get((ix+1, iy+1))
            if c00 is not None and c10 is not None and c11 is not None:
                faces.append([c00, c10, c11])
            if c00 is not None and c11 is not None and c01 is not None:
                faces.append([c00, c11, c01])

        # 7. Side walls at boundary
        def _wall(ta, tb, ba, bb, flip: bool) -> None:
            if any(v is None for v in [ta, tb, ba, bb]):
                return
            if flip:
                faces.extend([[ta, ba, bb], [ta, bb, tb]])
            else:
                faces.extend([[ta, tb, bb], [ta, bb, ba]])

        for (ix, iy) in filled_cells:
            if (ix+1, iy) not in filled_cells:
                _wall(top_verts.get((ix+1, iy)),   top_verts.get((ix+1, iy+1)),
                      bot_verts.get((ix+1, iy)),   bot_verts.get((ix+1, iy+1)), False)
            if (ix-1, iy) not in filled_cells:
                _wall(top_verts.get((ix, iy)),     top_verts.get((ix, iy+1)),
                      bot_verts.get((ix, iy)),     bot_verts.get((ix, iy+1)),   True)
            if (ix, iy-1) not in filled_cells:
                _wall(top_verts.get((ix,   iy)),   top_verts.get((ix+1, iy)),
                      bot_verts.get((ix,   iy)),   bot_verts.get((ix+1, iy)),   False)
            if (ix, iy+1) not in filled_cells:
                _wall(top_verts.get((ix,   iy+1)), top_verts.get((ix+1, iy+1)),
                      bot_verts.get((ix,   iy+1)), bot_verts.get((ix+1, iy+1)), True)

        if not faces:
            return None

        result = trimesh.Trimesh(
            vertices=np.array(vertices, dtype=float),
            faces=np.array(faces,       dtype=int),
            process=False,
        )

        # Taubin smoothing rounds the staircase pixel corners into smooth curves.
        trimesh.smoothing.filter_taubin(result, iterations=15)

        # Repair to manifold so Bambu Studio / PrusaSlicer accept the mesh (no error 9140).
        result = self._repair_to_manifold(result)
        return result

    # ── Support generation ────────────────────────────────────────────────────

    def _generate_supports(
        self,
        mesh: trimesh.Trimesh,
        mode: str = "auto",
        angle_threshold: float = 45.0,
        grid_spacing: float = 3.0,
        radius: float = 0.5,
    ) -> "trimesh.Trimesh | None":
        """Generate cylindrical support pillars under overhanging faces."""
        if mode == "none":
            return None

        z_min = float(mesh.bounds[0][2])
        cos_thresh = np.cos(np.radians(90.0 - angle_threshold))

        # Overhang faces: normal has enough downward component
        face_down = -(mesh.face_normals[:, 2])   # dot with (0,0,-1)
        overhang_mask = (face_down > cos_thresh) if mode == "auto" else (face_down > 0.1)

        if not np.any(overhang_mask):
            log.info("Support generation: no overhangs found.")
            return None

        # Sub-mesh of overhang faces for sampling
        sub = trimesh.Trimesh(
            vertices=mesh.vertices,
            faces=mesh.faces[overhang_mask],
            process=False,
        )
        n_samples = min(2000, max(100, int(sub.area / (grid_spacing ** 2))))
        try:
            pts, _ = trimesh.sample.sample_surface(sub, n_samples)
        except Exception:
            pts = sub.triangles_center

        # Deduplicate by XY grid
        grid = np.floor(pts[:, :2] / grid_spacing).astype(int)
        _, uid = np.unique(grid, axis=0, return_index=True)
        candidates = pts[uid]

        # Batch-raycast downward from each candidate to check if supported
        n = len(candidates)
        origins = candidates.copy()
        origins[:, 2] -= 0.15  # start just below the surface
        dirs    = np.tile([0., 0., -1.], (n, 1)).astype(float)

        intersector = trimesh.ray.ray_triangle.RayMeshIntersector(mesh)
        all_locs, all_rids, _ = intersector.intersects_location(
            origins, dirs, multiple_hits=True
        )

        # Mark candidates that already have mesh within 1.5 mm below them
        supported: set[int] = set()
        for loc, rid in zip(all_locs, all_rids):
            if candidates[rid][2] - loc[2] < 1.5:
                supported.add(int(rid))

        # Build support pillars
        parts = []
        for i, pt in enumerate(candidates):
            if i in supported:
                continue
            top_z = float(pt[2])
            if top_z - z_min < 0.8:
                continue
            try:
                cyl = trimesh.creation.cylinder(
                    radius=radius,
                    segment=[[pt[0], pt[1], z_min],
                             [pt[0], pt[1], top_z - 0.2]],
                    sections=6,
                )
                parts.append(cyl)
            except Exception:
                continue

        if not parts:
            log.info("Support generation: all candidates already supported.")
            return None

        log.info(f"Support generation: {len(parts)} pillars.")
        return trimesh.util.concatenate(parts) if len(parts) > 1 else parts[0]

    # ── Brim generation ───────────────────────────────────────────────────────

    def _generate_brim(
        self,
        mesh: trimesh.Trimesh,
        brim_width: float = 5.0,
        layer_height: float = 0.2,
    ) -> "trimesh.Trimesh | None":
        """Extrude a flat brim ring around the model footprint."""
        if brim_width <= 0:
            return None

        z_min = float(mesh.bounds[0][2])

        try:
            section = mesh.section(
                plane_origin=[0, 0, z_min + 0.05],
                plane_normal=[0, 0, 1],
            )
            if section is None:
                return None
            path_2d, _ = section.to_planar()
            polys = path_2d.polygons_full
            if not polys:
                return None
        except Exception as e:
            log.warning(f"Brim section failed: {e}")
            return None

        try:
            from shapely.ops import unary_union

            footprint = unary_union([p for p in polys if p.is_valid and not p.is_empty])
            outer     = footprint.buffer(brim_width, join_style=2)
            ring      = outer.difference(footprint)

            geoms = [ring] if ring.geom_type == "Polygon" \
                else (list(ring.geoms) if hasattr(ring, "geoms") else [])

            parts = []
            for g in geoms:
                if g.is_empty or g.area < 0.1:
                    continue
                part = trimesh.creation.extrude_polygon(g, height=layer_height)
                part.apply_translation([0, 0, z_min])
                parts.append(part)

            if not parts:
                return None

            log.info(f"Brim generation: {len(parts)} polygon(s), width={brim_width} mm.")
            return trimesh.util.concatenate(parts) if len(parts) > 1 else parts[0]

        except Exception as e:
            log.warning(f"Brim generation failed: {e}")
            return None

    # ── DB update ─────────────────────────────────────────────────────────────

    def _update_job(self, job_id: str, status: str, *,
                    output_key: str = None, error: str = None) -> None:
        self._ensure_conn()
        with self.conn.cursor() as cur:
            cur.execute(
                "UPDATE jobs SET status=%s, output_key=%s, error=%s, updated_at=NOW() WHERE id=%s",
                (status, output_key, error, job_id),
            )
            self.conn.commit()
