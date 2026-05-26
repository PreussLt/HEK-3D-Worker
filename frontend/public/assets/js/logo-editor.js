import { ModelViewer } from '@app/viewer';
import * as THREE from 'three';

// Rotation matrices for each print direction (applied to already-centered viewer geometry)
const _PRINT_DIR_MATRICES = {
    flip_z: new THREE.Matrix4().makeRotationX(Math.PI),
    x_pos:  new THREE.Matrix4().makeRotationY(-Math.PI / 2),
    x_neg:  new THREE.Matrix4().makeRotationY( Math.PI / 2),
    y_pos:  new THREE.Matrix4().makeRotationX(-Math.PI / 2),
    y_neg:  new THREE.Matrix4().makeRotationX( Math.PI / 2),
};

export class LogoEditor extends ModelViewer {
    constructor(canvas, opts = {}) {
        super(canvas, { background: 0x111827, ...opts });

        this._layers              = new Map();  // id → { mesh, tex, aspect, placement, color }
        this._activeId            = null;
        this.onLayerPlaced        = null;   // callback(id)
        this._mouseStart          = null;
        this._printDirMatrix      = null;   // current rotation applied to viewer geometry
        this._printDirKey         = 'none';

        canvas.style.cursor = 'crosshair';
        canvas.addEventListener('mousedown', e => { this._mouseStart = [e.clientX, e.clientY]; });
        canvas.addEventListener('click',     e => this._onClick(e));
    }

    // ── Layer management ───────────────────────────────────────────────────────

    addLayer(id, color = '#ffffff') {
        this._layers.set(id, {
            mesh:      null,
            tex:       null,
            aspect:    1,
            placement: { position: null, normal: null, size: 0.3, rotation: 0 },
            color,
        });
        this._activeId = id;
    }

    removeLayer(id) {
        const layer = this._layers.get(id);
        if (!layer) return;
        if (layer.mesh) { this.scene.remove(layer.mesh); layer.mesh.geometry.dispose(); }
        if (layer.tex)  layer.tex.dispose();
        this._layers.delete(id);
        if (this._activeId === id) {
            const keys = [...this._layers.keys()];
            this._activeId = keys.length > 0 ? keys[keys.length - 1] : null;
        }
    }

    activateLayer(id) {
        if (this._layers.has(id)) this._activeId = id;
    }

    // ── Per-layer controls ─────────────────────────────────────────────────────

    setLayerLogo(id, url) {
        const layer = this._layers.get(id);
        if (!layer) return;
        new THREE.TextureLoader().load(url, (tex) => {
            if (layer.tex) layer.tex.dispose();
            layer.tex    = tex;
            layer.aspect = tex.image.width / tex.image.height;
            this._rebuildLayerMesh(id);
        });
    }

    setLayerColor(id, hex) {
        const layer = this._layers.get(id);
        if (!layer) return;
        layer.color = hex;
        if (layer.mesh) layer.mesh.material.color.set(hex);
    }

    setLayerSize(id, v) {
        const layer = this._layers.get(id);
        if (!layer) return;
        layer.placement.size = v;
        if (layer.mesh) layer.mesh.scale.setScalar(v);
    }

    setLayerRotation(id, v) {
        const layer = this._layers.get(id);
        if (!layer) return;
        layer.placement.rotation = v;
        this._applyLayerPlacement(id);
    }

    restoreLayerPlacement(id, placementJson) {
        const layer = this._layers.get(id);
        if (!layer || !placementJson?.position) return;
        layer.placement.position = new THREE.Vector3(...placementJson.position);
        layer.placement.normal   = new THREE.Vector3(...placementJson.normal);
        layer.placement.size     = placementJson.size     ?? layer.placement.size;
        layer.placement.rotation = placementJson.rotation ?? layer.placement.rotation;
        if (layer.mesh) {
            layer.mesh.visible = true;
            this._applyLayerPlacement(id);
        }
    }

    getLayerPlacement(id) {
        const layer = this._layers.get(id);
        if (!layer) return null;
        const { position, normal, size, rotation } = layer.placement;
        if (!position) return null;
        return {
            position:         position.toArray(),
            normal:           normal.toArray(),
            size,
            rotation,
            viewer_transform: this._meshTransform ?? null,
        };
    }

    // ── Print direction ────────────────────────────────────────────────────────

    /** Rotate the displayed model geometry. viewer_transform stays unchanged so
     *  the worker's coordinate conversion remains valid. Returns true if any
     *  placed layers were cleared (caller should warn the user). */
    setPrintDirection(direction) {
        // Undo current rotation first
        if (this._printDirMatrix) {
            const inv = this._printDirMatrix.clone().invert();
            this._rotateViewerGeometry(inv);
        }

        this._printDirKey    = direction || 'none';
        this._printDirMatrix = _PRINT_DIR_MATRICES[this._printDirKey] ?? null;

        if (this._printDirMatrix) {
            this._rotateViewerGeometry(this._printDirMatrix);
        }

        // Clear placements — they are now invalid
        let hadPlaced = false;
        for (const layer of this._layers.values()) {
            if (layer.placement.position) {
                hadPlaced = true;
                layer.placement.position = null;
                layer.placement.normal   = null;
                if (layer.mesh) layer.mesh.visible = false;
            }
        }
        return hadPlaced;
    }

    get printDirection() { return this._printDirKey; }

    _rotateViewerGeometry(matrix) {
        if (!this.mesh) return;
        this.mesh.traverse(o => {
            if (o.isMesh && o.geometry) o.geometry.applyMatrix4(matrix);
        });
    }

    // ── Backward-compat single-layer API ───────────────────────────────────────

    setLogo(url) {
        if (!this._activeId) this.addLayer('default');
        this.setLayerLogo(this._activeId, url);
    }

    setLogoColor(hex) {
        if (this._activeId) this.setLayerColor(this._activeId, hex);
    }

    setSize(v) {
        if (this._activeId) this.setLayerSize(this._activeId, v);
    }

    setRotation(v) {
        if (this._activeId) this.setLayerRotation(this._activeId, v);
    }

    getPlacement() {
        if (!this._activeId) return null;
        return this.getLayerPlacement(this._activeId);
    }

    get logoMesh() {
        return this._layers.get(this._activeId)?.mesh ?? null;
    }

    // ── Internal mesh management ───────────────────────────────────────────────

    _rebuildLayerMesh(id) {
        const layer = this._layers.get(id);
        if (!layer?.tex) return;

        const mat = new THREE.MeshBasicMaterial({
            map:         layer.tex,
            color:       new THREE.Color(layer.color),
            transparent: true,
            depthWrite:  false,
            side:        THREE.DoubleSide,
            alphaTest:   0.05,
        });
        const geo = new THREE.PlaneGeometry(layer.aspect, 1);
        if (layer.mesh) { this.scene.remove(layer.mesh); layer.mesh.geometry.dispose(); }
        layer.mesh = new THREE.Mesh(geo, mat);
        layer.mesh.renderOrder = 1;
        layer.mesh.visible     = !!layer.placement.position;
        if (layer.placement.position) this._applyLayerPlacement(id);
        this.scene.add(layer.mesh);
    }

    _applyLayerPlacement(id) {
        const layer = this._layers.get(id);
        if (!layer?.mesh) return;
        const { position, normal, size, rotation } = layer.placement;
        if (!position) return;

        layer.mesh.position.copy(position).addScaledVector(normal, 0.025);

        // Build explicit tangent frame — matches the worker's coordinate system exactly.
        // setFromUnitVectors would pick an arbitrary in-plane orientation, causing rotation artifacts.
        const n = normal.clone().normalize();
        const upRef = Math.abs(n.dot(new THREE.Vector3(0, 1, 0))) > 0.9
            ? new THREE.Vector3(1, 0, 0)
            : new THREE.Vector3(0, 1, 0);
        const tangent   = new THREE.Vector3().crossVectors(upRef, n).normalize();
        const bitangent = new THREE.Vector3().crossVectors(n, tangent).normalize();

        // local X = tangent (texture U), local Y = bitangent (texture V "up"), local Z = normal
        layer.mesh.quaternion.setFromRotationMatrix(
            new THREE.Matrix4().makeBasis(tangent, bitangent, n)
        );
        layer.mesh.rotateZ(rotation);
        layer.mesh.scale.setScalar(size);
    }

    // ── Interaction ────────────────────────────────────────────────────────────

    _onClick(e) {
        if (!this._activeId) return;
        const layer = this._layers.get(this._activeId);
        if (!layer?.mesh) return;

        if (this._mouseStart) {
            const dx = e.clientX - this._mouseStart[0];
            const dy = e.clientY - this._mouseStart[1];
            if (Math.hypot(dx, dy) > 5) return;
        }

        const rect  = this.canvas.getBoundingClientRect();
        const mouse = new THREE.Vector2(
            ((e.clientX - rect.left) / rect.width)  *  2 - 1,
            ((e.clientY - rect.top)  / rect.height) * -2 + 1,
        );

        const rc   = new THREE.Raycaster();
        rc.setFromCamera(mouse, this.camera);
        const hits = rc.intersectObjects(this.getMeshes());

        if (hits.length > 0) {
            const hit    = hits[0];
            const normal = hit.face.normal.clone()
                .transformDirection(hit.object.matrixWorld)
                .normalize();

            layer.placement.position = hit.point.clone();
            layer.placement.normal   = normal;
            this._applyLayerPlacement(this._activeId);
            layer.mesh.visible = true;

            this.onLayerPlaced?.(this._activeId);
        }
    }
}
