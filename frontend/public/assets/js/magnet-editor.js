import { ModelViewer } from '@app/viewer';
import * as THREE from 'three';

// Andrew's monotone chain — returns hull vertices in CCW order
function convexHull2D(pts) {
    if (pts.length < 3) return [...pts];
    const s = [...pts].sort((a, b) => a[0] !== b[0] ? a[0] - b[0] : a[1] - b[1]);
    const cross = (O, A, B) =>
        (A[0] - O[0]) * (B[1] - O[1]) - (A[1] - O[1]) * (B[0] - O[0]);
    const lower = [], upper = [];
    for (const p of s) {
        while (lower.length >= 2 && cross(lower[lower.length - 2], lower[lower.length - 1], p) <= 0)
            lower.pop();
        lower.push(p);
    }
    for (const p of [...s].reverse()) {
        while (upper.length >= 2 && cross(upper[upper.length - 2], upper[upper.length - 1], p) <= 0)
            upper.pop();
        upper.push(p);
    }
    upper.pop(); lower.pop();
    return lower.concat(upper);
}

// Sample n evenly-spaced points along the convex hull perimeter
function samplePerimeter(hull, n) {
    const loop = [...hull, hull[0]];
    const lens = loop.slice(0, -1).map((p, i) =>
        Math.hypot(loop[i + 1][0] - p[0], loop[i + 1][1] - p[1])
    );
    const perim = lens.reduce((a, b) => a + b, 0);
    if (perim < 1e-9) return Array(n).fill(hull[0]);
    const result = [];
    for (let k = 0; k < n; k++) {
        const target = (k / n) * perim;
        let acc = 0;
        for (let j = 0; j < lens.length; j++) {
            if (acc + lens[j] >= target - 1e-9) {
                const t = Math.min(1, (target - acc) / (lens[j] || 1));
                result.push([
                    loop[j][0] * (1 - t) + loop[j + 1][0] * t,
                    loop[j][1] * (1 - t) + loop[j + 1][1] * t,
                ]);
                break;
            }
            acc += lens[j];
        }
    }
    return result;
}

export class MagnetEditor extends ModelViewer {
    constructor(canvas, opts = {}) {
        super(canvas, { background: 0x111827, ...opts });

        this._nMagnets   = 4;
        this._diameter   = 6;          // mm in original mesh units
        this._pattern    = 'perimeter';

        this._selection   = null;       // {normal, centroid, geo, faceIndices}
        this._tangent     = null;       // THREE.Vector3 (viewer world space)
        this._bitangent   = null;       // THREE.Vector3
        this._positions2D = [];         // [[u,v], ...] in viewer-world tangent coords

        this._dragState   = null;       // {index, plane} while dragging a hole

        this._highlightMesh = null;
        this._previewGroup  = new THREE.Group();
        this.scene.add(this._previewGroup);

        this._mouseStart = null;
        canvas.style.cursor = 'crosshair';

        canvas.addEventListener('mousedown', e => this._onMouseDown(e));
        canvas.addEventListener('mousemove', e => {
            if (!this._dragState && this._positions2D.length)
                canvas.style.cursor = this._hitHoleIndex(e) !== null ? 'grab' : 'crosshair';
        });
        canvas.addEventListener('click', e => this._onClick(e));

        this.onSelect = null;
        this.onClear  = null;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    setNMagnets(n) {
        this._nMagnets = Math.max(1, Math.min(8, Math.round(n)));
        this._resetPositions();
    }

    setDiameter(d) {
        this._diameter = Math.max(0.5, d);
        this._rebuildPreview();
    }

    setPattern(p) {
        this._pattern = p;
        this._resetPositions();
    }

    getSelection() {
        if (!this._selection) return null;
        return {
            normal:           this._selection.normal.toArray(),
            centroid:         this._selection.centroid.toArray(),
            tangent:          this._tangent?.toArray()   ?? null,
            bitangent:        this._bitangent?.toArray() ?? null,
            positions_2d:     this._positions2D,
            viewer_transform: this._meshTransform ?? null,
        };
    }

    clearSelection() {
        this._selection   = null;
        this._tangent     = null;
        this._bitangent   = null;
        this._positions2D = [];
        this._removeHighlight();
        this._clearPreview();
        if (this.onClear) this.onClear();
    }

    _replaceMesh(obj) {
        super._replaceMesh(obj);
        this.clearSelection();
    }

    // ── Drag a single hole ────────────────────────────────────────────────────

    _onMouseDown(e) {
        this._mouseStart = [e.clientX, e.clientY];
        if (!this._positions2D.length || !this._selection) return;

        const idx = this._hitHoleIndex(e);
        if (idx === null) return;

        const plane = new THREE.Plane().setFromNormalAndCoplanarPoint(
            this._selection.normal, this._selection.centroid
        );
        this._dragState = { index: idx, plane };
        this.controls.enabled = false;
        this.canvas.style.cursor = 'grabbing';

        const onMove = (ev) => {
            if (!this._dragState || !this._selection) return;
            const pt = this._mousePlaneIntersect(ev, this._dragState.plane);
            if (!pt) return;
            const c = this._selection.centroid;
            const rx = pt.x - c.x, ry = pt.y - c.y, rz = pt.z - c.z;
            this._positions2D[this._dragState.index] = [
                rx * this._tangent.x   + ry * this._tangent.y   + rz * this._tangent.z,
                rx * this._bitangent.x + ry * this._bitangent.y + rz * this._bitangent.z,
            ];
            this._rebuildPreview();
        };
        const onUp = () => {
            this._dragState = null;
            this.controls.enabled = true;
            this.canvas.style.cursor = 'crosshair';
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup',   onUp);
        };
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup',   onUp);
        e.preventDefault();
    }

    _hitHoleIndex(e) {
        const discs = this._previewGroup.children.filter(c => c.userData.magnetIndex !== undefined);
        if (!discs.length) return null;
        const rect  = this.canvas.getBoundingClientRect();
        const mouse = new THREE.Vector2(
            ((e.clientX - rect.left) / rect.width)  *  2 - 1,
            ((e.clientY - rect.top)  / rect.height) * -2 + 1,
        );
        const rc   = new THREE.Raycaster();
        rc.setFromCamera(mouse, this.camera);
        const hits = rc.intersectObjects(discs, false);
        return hits.length ? hits[0].object.userData.magnetIndex : null;
    }

    _mousePlaneIntersect(e, plane) {
        const rect  = this.canvas.getBoundingClientRect();
        const mouse = new THREE.Vector2(
            ((e.clientX - rect.left) / rect.width)  *  2 - 1,
            ((e.clientY - rect.top)  / rect.height) * -2 + 1,
        );
        const rc = new THREE.Raycaster();
        rc.setFromCamera(mouse, this.camera);
        const pt = new THREE.Vector3();
        return rc.ray.intersectPlane(plane, pt) ? pt : null;
    }

    // ── Click / face picking ──────────────────────────────────────────────────

    _onClick(e) {
        if (this._mouseStart) {
            const dx = e.clientX - this._mouseStart[0];
            const dy = e.clientY - this._mouseStart[1];
            if (Math.hypot(dx, dy) > 5) return;
        }
        // Don't re-select face when clicking on a hole disc
        if (this._positions2D.length && this._hitHoleIndex(e) !== null) return;

        if (!this.mesh) return;

        const rect  = this.canvas.getBoundingClientRect();
        const mouse = new THREE.Vector2(
            ((e.clientX - rect.left) / rect.width)  *  2 - 1,
            ((e.clientY - rect.top)  / rect.height) * -2 + 1,
        );
        const rc   = new THREE.Raycaster();
        rc.setFromCamera(mouse, this.camera);
        const hits = rc.intersectObjects(this.getMeshes(), true);
        if (!hits.length) return;

        const hit    = hits[0];
        const normal = hit.face.normal.clone()
            .transformDirection(hit.object.matrixWorld)
            .normalize();

        // Build and store tangent frame
        const up = new THREE.Vector3(0, 1, 0);
        if (Math.abs(normal.dot(up)) > 0.9) up.set(1, 0, 0);
        this._tangent   = up.clone().cross(normal).normalize();
        this._bitangent = normal.clone().cross(this._tangent).normalize();

        const geo         = hit.object.geometry;
        const faceIndices = this._findCoplanarFaces(geo, normal, hit.faceIndex);
        const centroid    = this._computeCentroid(geo, faceIndices);

        this._selection   = { normal, centroid, geo, faceIndices };
        this._positions2D = this._computePositions2D();

        this._rebuildHighlight();
        this._rebuildPreview();

        if (this.onSelect) this.onSelect(faceIndices.length);
    }

    _resetPositions() {
        if (!this._selection) return;
        this._positions2D = this._computePositions2D();
        this._rebuildPreview();
    }

    // ── Pattern computation (2D tangent-space positions) ──────────────────────

    _computePositions2D() {
        const { geo, faceIndices, centroid } = this._selection;
        const pos  = geo.getAttribute('position');

        // Project all face vertices into 2D tangent space
        const pts2d = [];
        const seen  = new Set();
        for (const fi of faceIndices) {
            const vi = fi * 3;
            for (let j = 0; j < 3; j++) {
                const vx = pos.getX(vi+j), vy = pos.getY(vi+j), vz = pos.getZ(vi+j);
                const k  = `${vx.toFixed(4)},${vy.toFixed(4)},${vz.toFixed(4)}`;
                if (seen.has(k)) continue;
                seen.add(k);
                const rx = vx - centroid.x, ry = vy - centroid.y, rz = vz - centroid.z;
                pts2d.push([
                    rx * this._tangent.x   + ry * this._tangent.y   + rz * this._tangent.z,
                    rx * this._bitangent.x + ry * this._bitangent.y + rz * this._bitangent.z,
                ]);
            }
        }
        if (pts2d.length < 3) return [[0, 0]];

        const hull   = convexHull2D(pts2d);
        const scale  = this._meshTransform?.scale ?? 0.01;
        const margin = this._diameter * 1.5 * scale;
        const n      = this._nMagnets;

        // Hull bounding box (used by all patterns)
        const hCenter = hull.reduce((s, p) => [s[0]+p[0], s[1]+p[1]], [0, 0]).map(v => v / hull.length);
        const minU = Math.min(...hull.map(p => p[0])), maxU = Math.max(...hull.map(p => p[0]));
        const minV = Math.min(...hull.map(p => p[1])), maxV = Math.max(...hull.map(p => p[1]));
        const cU   = (minU + maxU) / 2,  cV = (minV + maxV) / 2;
        const halfU = Math.max(margin * 0.5, (maxU - minU) / 2 - margin);
        const halfV = Math.max(margin * 0.5, (maxV - minV) / 2 - margin);

        const linePts = (axis, reverse = false) => {
            if (n === 1) return [[cU, cV]];
            return Array.from({ length: n }, (_, i) => {
                const t = n > 1 ? (i / (n - 1) - 0.5) * 2 * (reverse ? -1 : 1) : 0;
                return axis === 'u' ? [cU + t * halfU, cV] : [cU, cV + t * halfV];
            });
        };

        switch (this._pattern) {
            case 'line_h':     return linePts('u');
            case 'line_v':     return linePts('v');
            case 'diagonal':   {
                if (n === 1) return [[cU, cV]];
                return Array.from({ length: n }, (_, i) => {
                    const t = n > 1 ? (i / (n - 1) - 0.5) * 2 : 0;
                    return [cU + t * halfU, cV + t * halfV];
                });
            }
            case 'diagonal2':  {
                if (n === 1) return [[cU, cV]];
                return Array.from({ length: n }, (_, i) => {
                    const t = n > 1 ? (i / (n - 1) - 0.5) * 2 : 0;
                    return [cU + t * halfU, cV - t * halfV];
                });
            }
            case 'circle': {
                const r = Math.min(halfU, halfV);
                return Array.from({ length: n }, (_, i) => {
                    const a = (i / n) * Math.PI * 2 - Math.PI / 2;
                    return [cU + Math.cos(a) * r, cV + Math.sin(a) * r];
                });
            }
            case 'grid': {
                const cols = Math.ceil(Math.sqrt(n));
                const rows = Math.ceil(n / cols);
                const pts  = [];
                for (let r = 0; r < rows && pts.length < n; r++)
                    for (let c = 0; c < cols && pts.length < n; c++) {
                        const tu = cols > 1 ? (c / (cols - 1) - 0.5) * 2 : 0;
                        const tv = rows > 1 ? (r / (rows - 1) - 0.5) * 2 : 0;
                        pts.push([cU + tu * halfU, cV + tv * halfV]);
                    }
                return pts;
            }
            case 'perimeter':
            default: {
                const inset = p => {
                    const dx = hCenter[0] - p[0], dy = hCenter[1] - p[1];
                    const d  = Math.hypot(dx, dy);
                    return d < margin * 1.5
                        ? [hCenter[0], hCenter[1]]
                        : [p[0] + dx / d * margin, p[1] + dy / d * margin];
                };
                return samplePerimeter(hull, n).map(inset);
            }
        }
    }

    // ── Face grouping ─────────────────────────────────────────────────────────

    _findCoplanarFaces(geo, normal, startFaceIndex) {
        const pos    = geo.getAttribute('position');
        const nFaces = Math.floor(pos.count / 3);
        const cos5   = Math.cos(5 * Math.PI / 180);

        // Pass 1: all coplanar faces
        const coplanarSet = new Set();
        for (let fi = 0; fi < nFaces; fi++) {
            if (this._faceNormal(pos, fi * 3).dot(normal) >= cos5)
                coplanarSet.add(fi);
        }
        if (!coplanarSet.size) return [];

        // Pass 2: vertex-key → face adjacency within coplanar set
        const v2f = new Map();
        for (const fi of coplanarSet) {
            const vi = fi * 3;
            for (let j = 0; j < 3; j++) {
                const k = `${pos.getX(vi+j).toFixed(4)},${pos.getY(vi+j).toFixed(4)},${pos.getZ(vi+j).toFixed(4)}`;
                if (!v2f.has(k)) v2f.set(k, []);
                v2f.get(k).push(fi);
            }
        }

        // Pass 3: BFS from clicked face — only follow edges within coplanarSet
        const seed    = coplanarSet.has(startFaceIndex) ? startFaceIndex : coplanarSet.values().next().value;
        const visited = new Set([seed]);
        const queue   = [seed];
        while (queue.length) {
            const fi = queue.shift();
            const vi = fi * 3;
            for (let j = 0; j < 3; j++) {
                const k = `${pos.getX(vi+j).toFixed(4)},${pos.getY(vi+j).toFixed(4)},${pos.getZ(vi+j).toFixed(4)}`;
                for (const nfi of (v2f.get(k) ?? [])) {
                    if (!visited.has(nfi)) { visited.add(nfi); queue.push(nfi); }
                }
            }
        }
        return [...visited];
    }

    _faceNormal(pos, vi) {
        const ax = pos.getX(vi),   ay = pos.getY(vi),   az = pos.getZ(vi);
        const ux = pos.getX(vi+1) - ax, uy = pos.getY(vi+1) - ay, uz = pos.getZ(vi+1) - az;
        const vx = pos.getX(vi+2) - ax, vy = pos.getY(vi+2) - ay, vz = pos.getZ(vi+2) - az;
        const nx = uy*vz - uz*vy, ny = uz*vx - ux*vz, nz = ux*vy - uy*vx;
        const l  = Math.sqrt(nx*nx + ny*ny + nz*nz) || 1;
        return new THREE.Vector3(nx/l, ny/l, nz/l);
    }

    _computeCentroid(geo, faceIndices) {
        const pos = geo.getAttribute('position');
        let sx = 0, sy = 0, sz = 0, n = 0;
        for (const fi of faceIndices) {
            const vi = fi * 3;
            for (let j = 0; j < 3; j++) {
                sx += pos.getX(vi+j); sy += pos.getY(vi+j); sz += pos.getZ(vi+j); n++;
            }
        }
        return new THREE.Vector3(sx/n, sy/n, sz/n);
    }

    // ── Highlight selected face ───────────────────────────────────────────────

    _rebuildHighlight() {
        this._removeHighlight();
        if (!this._selection) return;

        const { geo, faceIndices, normal } = this._selection;
        const src = geo.getAttribute('position');
        const buf = new Float32Array(faceIndices.length * 9);

        faceIndices.forEach((fi, idx) => {
            const vi = fi * 3;
            for (let j = 0; j < 3; j++) {
                const b = idx * 9 + j * 3;
                buf[b]     = src.getX(vi+j) + normal.x * 0.005;
                buf[b + 1] = src.getY(vi+j) + normal.y * 0.005;
                buf[b + 2] = src.getZ(vi+j) + normal.z * 0.005;
            }
        });

        const hGeo = new THREE.BufferGeometry();
        hGeo.setAttribute('position', new THREE.BufferAttribute(buf, 3));
        this._highlightMesh = new THREE.Mesh(hGeo, new THREE.MeshBasicMaterial({
            color: 0xa78bfa, transparent: true, opacity: 0.5,
            side: THREE.DoubleSide, depthWrite: false,
        }));
        this._highlightMesh.renderOrder = 1;
        this.scene.add(this._highlightMesh);
    }

    _removeHighlight() {
        if (!this._highlightMesh) return;
        this.scene.remove(this._highlightMesh);
        this._highlightMesh.geometry.dispose();
        this._highlightMesh.material.dispose();
        this._highlightMesh = null;
    }

    // ── Magnet hole preview ───────────────────────────────────────────────────

    _rebuildPreview() {
        this._clearPreview();
        if (!this._selection || !this._positions2D.length) return;

        const { normal, centroid } = this._selection;
        const scale  = this._meshTransform?.scale ?? 0.01;
        const radius = (this._diameter / 2) * scale;

        this._positions2D.forEach(([u, v], idx) => {
            const pt = centroid.clone()
                .addScaledVector(this._tangent,   u)
                .addScaledVector(this._bitangent, v);

            const disc = new THREE.Mesh(
                new THREE.CylinderGeometry(radius, radius, radius * 0.25, 32),
                new THREE.MeshBasicMaterial({ color: 0xfbbf24 })
            );
            disc.position.copy(pt).addScaledVector(normal, 0.007);
            disc.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), normal);
            disc.renderOrder = 2;
            disc.userData.magnetIndex = idx;   // needed for drag hit detection
            this._previewGroup.add(disc);

            const ring = new THREE.Mesh(
                new THREE.RingGeometry(radius * 1.0, radius * 1.22, 32),
                new THREE.MeshBasicMaterial({ color: 0xf59e0b, side: THREE.DoubleSide })
            );
            ring.position.copy(disc.position).addScaledVector(normal, 0.002);
            ring.quaternion.copy(disc.quaternion);
            ring.renderOrder = 3;
            this._previewGroup.add(ring);
        });
    }

    _clearPreview() {
        while (this._previewGroup.children.length) {
            const c = this._previewGroup.children[0];
            c.geometry.dispose();
            c.material.dispose();
            this._previewGroup.remove(c);
        }
    }
}
