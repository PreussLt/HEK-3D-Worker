import os
import json
import time
import zipfile
import tempfile
import logging
from xml.etree import ElementTree as ET

import numpy as np
import psycopg2
import boto3
import trimesh
import trimesh.creation
import trimesh.boolean
import trimesh.transformations as tf
from scipy.spatial import ConvexHull
from dotenv import load_dotenv

load_dotenv()

log = logging.getLogger(__name__)

SUPPORTED_FORMATS = {"stl", "obj", "ply", "glb", "gltf", "3mf"}
# How far above the surface the drill cylinder starts (ensures clean boolean cut)
_DRILL_EXTRA = 1.5


class MagnetJobProcessor:
    def __init__(self) -> None:
        self.conn = self._connect_with_retry()
        self.s3 = boto3.client(
            "s3",
            endpoint_url=os.getenv("RUSTFS_ENDPOINT", "http://rustfs:9000"),
            aws_access_key_id=os.getenv("RUSTFS_ACCESS_KEY"),
            aws_secret_access_key=os.getenv("RUSTFS_SECRET_KEY"),
        )
        self.bucket_models = os.getenv("RUSTFS_BUCKET_MODELS", "models")
        self.bucket_output = os.getenv("RUSTFS_BUCKET_OUTPUT", "output")
        self._ensure_buckets()

    # ── Bucket / DB boilerplate ───────────────────────────────────────────────

    def _ensure_buckets(self) -> None:
        for bucket in (self.bucket_models, self.bucket_output):
            try:
                self.s3.head_bucket(Bucket=bucket)
            except Exception:
                try:
                    self.s3.create_bucket(Bucket=bucket)
                    log.info(f"Bucket '{bucket}' created.")
                except Exception as e:
                    log.warning(f"Could not create bucket '{bucket}': {e}")

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
                log.info("PostgreSQL connected (MagnetProcessor).")
                return conn
            except psycopg2.OperationalError as exc:
                log.warning(f"DB not ready ({attempt}/{retries}): {exc}")
                if attempt == retries:
                    raise
                time.sleep(delay)

    def _reconnect(self) -> None:
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
                UPDATE magnet_jobs SET status = 'processing', updated_at = NOW()
                WHERE id = (
                    SELECT id FROM magnet_jobs WHERE status = 'pending'
                    ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED
                )
                RETURNING id, model_key, magnet_diameter, magnet_length, n_magnets, face_selection
                """
            )
            self.conn.commit()
            row = cur.fetchone()
            if row:
                face_sel = row[5]
                if isinstance(face_sel, str):
                    face_sel = json.loads(face_sel)
                return {
                    "id":              str(row[0]),
                    "model_key":       row[1],
                    "magnet_diameter": float(row[2]),
                    "magnet_length":   float(row[3]),
                    "n_magnets":       int(row[4]) if row[4] else 4,
                    "face_selection":  face_sel,
                }
        return None

    # ── Main processing pipeline ──────────────────────────────────────────────

    def process(self, job: dict) -> None:
        try:
            ext = job["model_key"].rsplit(".", 1)[-1].lower()
            if ext not in SUPPORTED_FORMATS:
                raise ValueError(f"Unsupported model format: {ext}")

            diameter = job["magnet_diameter"]
            length   = job["magnet_length"]

            with tempfile.TemporaryDirectory() as tmp:
                model_path    = os.path.join(tmp, f"model.{ext}")
                out_model     = os.path.join(tmp, "model_with_magnets.stl")
                out_template  = os.path.join(tmp, "drill_template.stl")

                self.s3.download_file(self.bucket_models, job["model_key"], model_path)

                mesh = self._load_mesh(model_path, ext)
                log.info(f"Mesh loaded: {len(mesh.vertices)} verts, {len(mesh.faces)} faces")

                n_magnets      = job.get("n_magnets", 4)
                face_selection = job.get("face_selection")
                positions = self._find_magnet_positions(
                    mesh, diameter, length,
                    face_selection=face_selection,
                    n_magnets=n_magnets,
                )
                log.info(f"Magnet positions found: {len(positions)}")

                if not positions:
                    raise ValueError("Keine geeigneten Flächen für Magnetlöcher gefunden.")

                drilled = self._drill_holes(mesh, positions, diameter, length)
                template = self._build_template(positions, diameter)

                drilled.export(out_model)
                template.export(out_template)

                model_key    = f"output/{job['id']}_magnets.stl"
                template_key = f"output/{job['id']}_template.stl"

                self.s3.upload_file(out_model,    self.bucket_output, model_key)
                self.s3.upload_file(out_template, self.bucket_output, template_key)

                self._update_job(job["id"], "done",
                                 output_key=model_key, template_key=template_key)

        except Exception as exc:
            log.error(f"Magnet job {job['id']} failed: {exc}", exc_info=True)
            self._update_job(job["id"], "error", error=str(exc))

    # ── Mesh loading ──────────────────────────────────────────────────────────

    def _load_mesh(self, path: str, ext: str) -> trimesh.Trimesh:
        if ext == "3mf":
            return self._load_3mf(path)
        obj = trimesh.load(path, force="mesh")
        if isinstance(obj, trimesh.Scene):
            meshes = [g for g in obj.geometry.values() if isinstance(g, trimesh.Trimesh)]
            obj = trimesh.util.concatenate(meshes) if meshes else trimesh.Trimesh()
        return obj

    def _load_3mf(self, path: str) -> trimesh.Trimesh:
        ns = "http://schemas.microsoft.com/3dmanufacturing/core/2015/02"
        meshes = []
        with zipfile.ZipFile(path) as z:
            for mf in [n for n in z.namelist() if n.endswith(".model")]:
                root = ET.fromstring(z.read(mf))
                for obj_el in (root.findall(f".//{{{ns}}}object") or root.findall(".//object")):
                    mesh_el = obj_el.find(f"{{{ns}}}mesh") or obj_el.find("mesh")
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

    # ── Magnet position finder ────────────────────────────────────────────────

    def _find_magnet_positions(
        self,
        mesh: trimesh.Trimesh,
        diameter: float,
        length: float,
        face_selection: dict | None = None,
        n_magnets: int = 4,
    ) -> list[dict]:
        if face_selection:
            # If the frontend sent explicit positions (from pattern/drag), use them directly
            if face_selection.get("positions_2d") and face_selection.get("tangent") and face_selection.get("bitangent"):
                return self._positions_from_2d(face_selection)
            return self._positions_on_selected_face(mesh, face_selection, n_magnets, diameter)

        # Automatic: largest flat face
        if not hasattr(mesh, "facets") or len(mesh.facets) == 0:
            log.warning("No facets found, using bounding-box fallback")
            return self._fallback_positions(mesh, diameter, n_magnets)

        face_areas = trimesh.triangles.area(mesh.triangles)
        min_area   = np.pi * (diameter / 2) ** 2 * 3

        facet_info = sorted(
            [(float(face_areas[fi].sum()), i, fi, mesh.facets_normal[i])
             for i, fi in enumerate(mesh.facets)],
            key=lambda x: x[0], reverse=True,
        )

        for area, _, face_indices, normal in facet_info[:5]:
            if area < min_area:
                break
            vert_indices = np.unique(mesh.faces[face_indices].flatten())
            face_verts   = mesh.vertices[vert_indices]
            if len(face_verts) < 3:
                continue
            centroid  = face_verts.mean(axis=0)
            positions = self._compute_positions_on_facet(
                normal, centroid, face_verts, n_magnets, diameter
            )
            if positions:
                log.info(f"Auto facet: area={area:.1f}, normal={np.round(normal,2)}, "
                         f"{len(positions)} positions")
                return positions

        log.warning("No suitable facet found, using bounding-box fallback")
        return self._fallback_positions(mesh, diameter, n_magnets)

    def _positions_from_2d(self, face_selection: dict) -> list[dict]:
        """Convert frontend 2D tangent-space positions directly to 3D mesh positions."""
        normal_w   = np.array(face_selection["normal"],    dtype=float)
        centroid_w = np.array(face_selection["centroid"],  dtype=float)
        tangent_w  = np.array(face_selection["tangent"],   dtype=float)
        bitan_w    = np.array(face_selection["bitangent"], dtype=float)
        normal_w  /= np.linalg.norm(normal_w)

        vt = face_selection.get("viewer_transform")
        scale  = float(vt["scale"])  if vt else 1.0
        center = np.array(vt["center"], dtype=float) if vt else np.zeros(3)

        centroid_orig = centroid_w / scale + center

        positions = []
        for (u, v) in face_selection["positions_2d"]:
            # viewer-space offset → original-mesh-space offset (scale is uniform)
            u_orig = float(u) / scale
            v_orig = float(v) / scale
            p3d = centroid_orig + u_orig * tangent_w + v_orig * bitan_w
            positions.append({
                "position_3d": p3d,
                "position_2d": np.array([u_orig, v_orig]),
                "normal":      normal_w,
                "tangent":     tangent_w,
                "bitangent":   bitan_w,
                "centroid":    centroid_orig,
            })
        log.info(f"Using {len(positions)} positions from frontend (pattern/drag)")
        return positions

    def _positions_on_selected_face(
        self,
        mesh: trimesh.Trimesh,
        face_selection: dict,
        n_magnets: int,
        diameter: float,
    ) -> list[dict]:
        normal_w   = np.array(face_selection["normal"],   dtype=float)
        centroid_w = np.array(face_selection["centroid"], dtype=float)
        normal_w  /= np.linalg.norm(normal_w)

        # Convert centroid viewer-world → original mesh space
        vt = face_selection.get("viewer_transform")
        if vt:
            scale         = float(vt["scale"])
            center        = np.array(vt["center"], dtype=float)
            centroid_orig = centroid_w / scale + center
        else:
            centroid_orig = centroid_w

        if not hasattr(mesh, "facets") or len(mesh.facets) == 0:
            return self._fallback_positions(mesh, diameter, n_magnets)

        face_areas = trimesh.triangles.area(mesh.triangles)
        cos15      = np.cos(15 * np.pi / 180)
        best, best_score = None, -np.inf

        for i, face_indices in enumerate(mesh.facets):
            facet_n = mesh.facets_normal[i]
            if abs(np.dot(facet_n, normal_w)) < cos15:
                continue
            vi   = np.unique(mesh.faces[face_indices].flatten())
            fv   = mesh.vertices[vi]
            fc   = fv.mean(axis=0)
            area = float(face_areas[face_indices].sum())
            dist = float(np.linalg.norm(fc - centroid_orig))
            score = abs(np.dot(facet_n, normal_w)) - dist * 0.05 + np.log1p(area) * 0.1
            if score > best_score:
                best_score = score
                best = (facet_n, fc, fv)

        if best is None:
            log.warning("No matching facet for face_selection, using fallback")
            return self._fallback_positions(mesh, diameter, n_magnets)

        normal, centroid, face_verts = best
        # Ensure normal points the same way the user clicked (outward from the surface)
        if np.dot(normal, normal_w) < 0:
            normal = -normal
        log.info(f"Selected facet: normal={np.round(normal,2)}, score={best_score:.3f}")
        return self._compute_positions_on_facet(normal, centroid, face_verts, n_magnets, diameter)

    def _compute_positions_on_facet(
        self,
        normal: np.ndarray,
        centroid: np.ndarray,
        face_verts: np.ndarray,
        n_magnets: int,
        diameter: float,
    ) -> list[dict]:
        tangent, bitangent = self._tangent_frame(normal)
        verts_2d = np.column_stack([
            np.dot(face_verts - centroid, tangent),
            np.dot(face_verts - centroid, bitangent),
        ])
        try:
            hull = ConvexHull(verts_2d)
        except Exception:
            return []

        hull_pts    = verts_2d[hull.vertices]
        hull_center = hull_pts.mean(axis=0)
        margin      = diameter * 1.5
        sampled     = self._sample_hull_perimeter(hull_pts, n_magnets)

        unique: list[dict] = []
        for p2d in sampled:
            vec  = hull_center - p2d
            dist = np.linalg.norm(vec)
            p_in = hull_center.copy() if dist < margin * 1.5 else p2d + vec / dist * margin
            p3d  = centroid + float(p_in[0]) * tangent + float(p_in[1]) * bitangent
            pos  = {
                "position_3d": p3d, "position_2d": p_in,
                "normal": normal,   "tangent": tangent,
                "bitangent": bitangent, "centroid": centroid,
            }
            if not any(np.linalg.norm(p3d - q["position_3d"]) < diameter * 1.2 for q in unique):
                unique.append(pos)
        return unique

    @staticmethod
    def _sample_hull_perimeter(hull_pts: np.ndarray, n: int) -> list[np.ndarray]:
        """Sample n evenly-spaced points along the closed convex hull perimeter."""
        loop    = np.vstack([hull_pts, hull_pts[0]])
        seg_len = np.linalg.norm(np.diff(loop, axis=0), axis=1)
        perim   = seg_len.sum()
        if perim < 1e-9:
            return [hull_pts[0]] * n
        result = []
        for k in range(n):
            target, acc = perim * k / n, 0.0
            for j, sl in enumerate(seg_len):
                if acc + sl >= target - 1e-9:
                    t = min(1.0, (target - acc) / (sl or 1))
                    result.append(loop[j] * (1 - t) + loop[j + 1] * t)
                    break
                acc += sl
            else:
                result.append(loop[-1])
        return result

    def _fallback_positions(self, mesh: trimesh.Trimesh, diameter: float,
                            n_magnets: int = 4) -> list[dict]:
        extents   = mesh.bounding_box.extents
        center    = mesh.centroid
        z_min     = float(mesh.bounds[0][2])
        normal    = np.array([0., 0., -1.])
        tangent   = np.array([1., 0.,  0.])
        bitangent = np.array([0., 1.,  0.])
        centroid  = np.array([center[0], center[1], z_min])

        hx = max(0.0, extents[0] / 2 - diameter * 1.5)
        hy = max(0.0, extents[1] / 2 - diameter * 1.5)

        if hx < diameter or hy < diameter:
            pts_2d = [np.array([0., 0.])] * min(n_magnets, 1)
        else:
            corners = [np.array([-hx, -hy]), np.array([hx, -hy]),
                       np.array([ hx,  hy]), np.array([-hx,  hy])]
            pts_2d  = self._sample_hull_perimeter(np.array(corners), n_magnets)

        return [
            {
                "position_3d": centroid + p[0] * tangent + p[1] * bitangent,
                "position_2d": p,
                "normal": normal, "tangent": tangent,
                "bitangent": bitangent, "centroid": centroid,
            }
            for p in pts_2d
        ]

    @staticmethod
    def _tangent_frame(normal: np.ndarray):
        up = np.array([0., 1., 0.])
        if abs(np.dot(normal, up)) > 0.9:
            up = np.array([1., 0., 0.])
        tangent   = np.cross(up, normal);   tangent   /= np.linalg.norm(tangent)
        bitangent = np.cross(normal, tangent)
        return tangent, bitangent

    # ── Drilling (boolean difference) ─────────────────────────────────────────

    def _drill_holes(
        self,
        mesh: trimesh.Trimesh,
        positions: list[dict],
        diameter: float,
        length: float,
    ) -> trimesh.Trimesh:
        # Repair mesh so manifold3d has the best chance of succeeding
        working = mesh.copy()
        if not working.is_watertight:
            log.info("Mesh is not watertight — attempting repair before boolean ops")
            working.fill_holes()
            working.fix_normals()

        cylinders = [
            self._make_cylinder(p["position_3d"], p["normal"], diameter, length)
            for p in positions
        ]

        try:
            combined = (
                cylinders[0]
                if len(cylinders) == 1
                else trimesh.boolean.union(cylinders, engine="manifold")
            )
            result = trimesh.boolean.difference([working, combined], engine="manifold")
            if result is None or len(result.faces) == 0:
                raise ValueError("Boolean difference returned an empty mesh")
            log.info(f"Boolean difference OK: {len(result.vertices)} verts, {len(result.faces)} faces")
            return result
        except Exception as exc:
            log.error(f"Boolean difference failed ({exc}); returning unmodified mesh")
            return mesh

    @staticmethod
    def _make_cylinder(center: np.ndarray, normal: np.ndarray,
                       diameter: float, length: float) -> trimesh.Trimesh:
        """
        Creates a cylinder centered so that it extends from _DRILL_EXTRA mm
        ABOVE the surface to `length` mm BELOW it.
        """
        height = length + _DRILL_EXTRA
        # Center of cylinder (along normal from surface point)
        cyl_center = center - normal * (length - _DRILL_EXTRA) / 2.0

        cyl = trimesh.creation.cylinder(radius=diameter / 2.0 + 0.05,
                                        height=height, sections=32)

        # Align cylinder's +Z axis with the surface normal
        z = np.array([0., 0., 1.])
        axis = np.cross(z, normal)
        axis_len = np.linalg.norm(axis)
        if axis_len > 1e-6:
            angle = np.arccos(np.clip(np.dot(z, normal), -1., 1.))
            cyl.apply_transform(tf.rotation_matrix(angle, axis / axis_len))
        elif np.dot(z, normal) < 0:
            cyl.apply_transform(tf.rotation_matrix(np.pi, [1., 0., 0.]))

        cyl.apply_translation(cyl_center)
        return cyl

    # ── Drill template ────────────────────────────────────────────────────────

    def _build_template(self, positions: list[dict], diameter: float) -> trimesh.Trimesh:
        """
        A flat 3 mm plate in the XY plane with through-holes at every magnet
        position (coordinates taken from each position's 2D face-frame).
        The user prints this, lays it on the model face and drills through.
        """
        pts = np.array([p["position_2d"] for p in positions], dtype=float)
        margin    = diameter * 2.5
        thickness = 3.0

        min_xy  = pts.min(axis=0) - margin
        max_xy  = pts.max(axis=0) + margin
        size    = np.maximum(max_xy - min_xy, [diameter * 5, diameter * 5])
        center2 = (min_xy + max_xy) / 2.0

        # Base plate (centered at origin in XY, sitting on Z=0)
        plate = trimesh.creation.box([size[0], size[1], thickness])
        plate.apply_translation([float(center2[0]), float(center2[1]), thickness / 2.0])

        # Drill holes (slightly oversized for easy drill-bit entry)
        holes = [
            self._make_through_hole(float(p["position_2d"][0]),
                                    float(p["position_2d"][1]),
                                    diameter, thickness)
            for p in positions
        ]

        try:
            combined_holes = (
                holes[0]
                if len(holes) == 1
                else trimesh.boolean.union(holes, engine="manifold")
            )
            template = trimesh.boolean.difference([plate, combined_holes], engine="manifold")
        except Exception as exc:
            log.error(f"Template boolean failed ({exc}); returning plate without holes")
            template = plate

        # Move template to Z=0 origin for easy printing
        template.apply_translation([-float(center2[0]), -float(center2[1]), 0.])
        return template

    @staticmethod
    def _make_through_hole(x: float, y: float,
                           diameter: float, plate_thickness: float) -> trimesh.Trimesh:
        h = plate_thickness + 2.0
        cyl = trimesh.creation.cylinder(radius=diameter / 2.0, height=h, sections=32)
        cyl.apply_translation([x, y, plate_thickness / 2.0])
        return cyl

    # ── DB update ─────────────────────────────────────────────────────────────

    def _update_job(self, job_id: str, status: str, *,
                    output_key: str = None, template_key: str = None,
                    error: str = None) -> None:
        self._ensure_conn()
        with self.conn.cursor() as cur:
            cur.execute(
                "UPDATE magnet_jobs SET status=%s, output_key=%s, template_key=%s, "
                "error=%s, updated_at=NOW() WHERE id=%s",
                (status, output_key, template_key, error, job_id),
            )
            self.conn.commit()
