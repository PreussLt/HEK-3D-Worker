import * as THREE from 'three';
import { STLLoader }     from 'three/addons/loaders/STLLoader.js';
import { OBJLoader }     from 'three/addons/loaders/OBJLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

const NS3MF = 'http://schemas.microsoft.com/3dmanufacturing/core/2015/02';

// ── XML helpers ───────────────────────────────────────────────────────────────

function _el(parent, tag) {
    return parent.getElementsByTagNameNS(NS3MF, tag)[0]
        || parent.getElementsByTagName(tag)[0]
        || null;
}
function _els(parent, tag) {
    const r = parent.getElementsByTagNameNS(NS3MF, tag);
    return Array.from(r.length ? r : parent.getElementsByTagName(tag));
}

// ── Shared 3MF helpers ────────────────────────────────────────────────────────

async function _findMainModel(zip) {
    const root = zip.file('_rels/.rels');
    if (root) {
        try {
            const doc = new DOMParser().parseFromString(await root.async('text'), 'text/xml');
            const rel = doc.querySelector('Relationship[Type*="3dmodel"]');
            if (rel) {
                const f = zip.file(rel.getAttribute('Target').replace(/^\//, ''));
                if (f) return f;
            }
        } catch (_) {}
    }
    for (const p of ['3D/3dmodel.model', '3d/3dmodel.model']) {
        const f = zip.file(p);
        if (f) return f;
    }
    return Object.values(zip.files).find(f => f.name.endsWith('.model')) ?? null;
}

async function _partFiles(zip, modelPath) {
    const dir  = modelPath.substring(0, modelPath.lastIndexOf('/') + 1);
    const name = modelPath.substring(modelPath.lastIndexOf('/') + 1);
    const rf   = zip.file(`${dir}_rels/${name}.rels`);
    if (!rf) return [];
    const doc  = new DOMParser().parseFromString(await rf.async('text'), 'text/xml');
    return [...doc.querySelectorAll('Relationship')]
        .map(r => zip.file(r.getAttribute('Target').replace(/^\//, '')))
        .filter(Boolean);
}

// Build { objectMap: Map<id,{el}>, componentRefs: Map<id,[id,...]> } from ALL model files.
// BambuStudio keeps assembly objects in the main file (they reference mesh objects in part
// files via <component objectid="…"/>). Plate metadata uses the assembly IDs, so we must
// expand those IDs transitively to reach the actual geometry.
async function _buildObjectMap(zip) {
    const mainFile = await _findMainModel(zip);
    if (!mainFile) return null;
    const allFiles = [mainFile, ...(await _partFiles(zip, mainFile.name))];

    const objectMap     = new Map();  // id -> { el }
    const componentRefs = new Map();  // id -> [refId, ...]

    for (const f of allFiles) {
        const doc  = new DOMParser().parseFromString(await f.async('text'), 'text/xml');
        let   objs = _els(doc.documentElement, 'object');

        for (const obj of objs) {
            const id = obj.getAttribute('id');
            if (!id) continue;
            objectMap.set(id, { el: obj });

            const refs = _els(obj, 'component')
                .map(c => c.getAttribute('objectid')).filter(Boolean);
            if (refs.length) componentRefs.set(id, refs);
        }
    }
    return { objectMap, componentRefs };
}

// BFS: add each seed's component targets (and their targets) to the expanded set.
function _expandIds(seeds, componentRefs) {
    const expanded = new Set(seeds);
    const queue    = [...seeds];
    while (queue.length) {
        const id = queue.shift();
        for (const r of componentRefs.get(id) ?? []) {
            if (!expanded.has(r)) { expanded.add(r); queue.push(r); }
        }
    }
    return expanded;
}

async function _parsePlatesFromZip(zip) {
    const f = zip.file('Metadata/model_settings.config');
    if (!f) return null;
    const doc   = new DOMParser().parseFromString(await f.async('text'), 'text/xml');
    const plEls = Array.from(doc.getElementsByTagName('plate'));
    if (!plEls.length) return null;

    const plates = [];
    for (const pl of plEls) {
        const meta = {};
        for (const m of pl.getElementsByTagName('metadata'))
            meta[m.getAttribute('key')] = m.getAttribute('value') ?? '';

        const id   = parseInt(meta['plater_id'] ?? (plates.length + 1));
        const name = meta['plater_name']?.trim() || `Platte ${id}`;
        const objectIds = new Set(
            Array.from(pl.getElementsByTagName('object'))
                .map(o => o.getAttribute('id')).filter(Boolean)
        );
        let thumbnailUrl = null;
        const tp = meta['thumbnail_file'];
        if (tp) {
            const tf = zip.file(tp) ?? zip.file(tp.replace(/^\//, ''));
            if (tf) thumbnailUrl = URL.createObjectURL(await tf.async('blob'));
        }
        plates.push({ id, name, objectIds, thumbnailUrl });
    }
    return plates.length ? plates : null;
}

// ── Exported parsers ──────────────────────────────────────────────────────────

export async function parsePlates3MF(url) {
    if (!window.JSZip) return null;
    try {
        const zip = await window.JSZip.loadAsync(await (await fetch(url)).arrayBuffer());
        return await _parsePlatesFromZip(zip);
    } catch (e) { console.warn('parsePlates3MF:', e); return null; }
}

// Returns { objects: [{id, name, type, hasGeometry, components, isTopLevel}], plates }
export async function parseStructure3MF(url) {
    if (!window.JSZip) return null;
    try {
        const zip    = await window.JSZip.loadAsync(await (await fetch(url)).arrayBuffer());
        const result = await _buildObjectMap(zip);
        if (!result) return null;
        const { objectMap, componentRefs } = result;

        const referenced = new Set();
        for (const refs of componentRefs.values()) for (const r of refs) referenced.add(r);

        const objects = [];
        for (const [id, { el }] of objectMap) {
            const type  = el.getAttribute('type') || 'model';
            const name  = el.getAttribute('name') || `Objekt ${id}`;
            const meshE = _el(el, 'mesh');
            objects.push({
                id,
                name,
                type,
                hasGeometry: !!meshE && !!_el(meshE, 'vertices'),
                components:  componentRefs.get(id) ?? [],
                isTopLevel:  !referenced.has(id),
            });
        }
        const plates = await _parsePlatesFromZip(zip);
        return { objects, plates };
    } catch (e) { console.warn('parseStructure3MF:', e); return null; }
}

// ─────────────────────────────────────────────────────────────────────────────

export class ModelViewer {
    constructor(canvas, opts = {}) {
        this.canvas = canvas;
        const w = canvas.clientWidth  || 300;
        const h = canvas.clientHeight || 200;

        this.renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
        this.renderer.setSize(w, h, false);
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));

        this.scene = new THREE.Scene();
        this.scene.background = new THREE.Color(opts.background ?? 0x1a1a2e);

        this.camera = new THREE.PerspectiveCamera(50, w / h, 0.001, 10000);
        this.camera.position.set(0, 0, 5);

        this.controls = new OrbitControls(this.camera, this.renderer.domElement);
        this.controls.enableDamping = true;
        this.controls.dampingFactor = 0.05;

        const dir  = new THREE.DirectionalLight(0xffffff, 0.9);
        dir.position.set(5, 10, 7);
        const fill = new THREE.DirectionalLight(0x4488ff, 0.3);
        fill.position.set(-5, -5, -5);
        this.scene.add(new THREE.AmbientLight(0xffffff, 0.6), dir, fill);

        this.mesh     = null;
        this._running = true;
        this._raf     = null;
        this._overlay = this._makeOverlay();

        new ResizeObserver(() => this._onResize()).observe(canvas);
        this._animate();
    }

    // ── Public API ────────────────────────────────────────────────────────────

    loadModel(url, format, opts = {}) {
        this._showOverlay('Lade…', '#60a5fa');
        return this._load(url, format, opts)
            .then(() => this._hideOverlay())
            .catch(err => {
                this._showOverlay('Vorschau nicht verfügbar\n' + err.message, '#f87171');
                throw err;
            });
    }

    getMeshes() {
        const out = [];
        if (this.mesh) this.mesh.traverse(o => { if (o.isMesh) out.push(o); });
        return out;
    }

    setModelColor(hex) {
        const color = new THREE.Color(hex);
        this.getMeshes().forEach(o => { if (o.material) o.material.color.set(color); });
    }

    pause()   { this._running = false; cancelAnimationFrame(this._raf); }
    resume()  { if (!this._running) { this._running = true; this._animate(); } }
    destroy() { this._running = false; cancelAnimationFrame(this._raf); this.renderer.dispose(); }

    // ── Loaders ───────────────────────────────────────────────────────────────

    _load(url, format, opts = {}) {
        const mat = () => new THREE.MeshPhongMaterial({ color: 0x4a9eff, specular: 0x222222, shininess: 60 });

        if (format === 'stl') {
            return new Promise((resolve, reject) =>
                new STLLoader().load(url, geo => {
                    geo.computeVertexNormals();
                    this._replaceMesh(new THREE.Mesh(geo, mat()));
                    resolve();
                }, undefined, reject)
            );
        }
        if (format === 'obj') {
            return new Promise((resolve, reject) =>
                new OBJLoader().load(url, group => {
                    group.traverse(o => { if (o.isMesh) o.material = mat(); });
                    this._replaceMesh(group);
                    resolve();
                }, undefined, reject)
            );
        }
        if (format === '3mf') return this._load3MF(url, opts);
        if (format === 'glb' || format === 'gltf') {
            return new Promise((resolve, reject) =>
                import('three/addons/loaders/GLTFLoader.js').then(({ GLTFLoader }) =>
                    new GLTFLoader().load(url, gltf => {
                        gltf.scene.traverse(o => {
                            if (o.isMesh && !o.material.vertexColors) {
                                o.material.vertexColors = true;
                                o.material.needsUpdate  = true;
                            }
                        });
                        this._replaceMesh(gltf.scene);
                        resolve();
                    }, undefined, reject)
                ).catch(reject)
            );
        }
        return Promise.reject(new Error(`Format "${format}" wird im Viewer nicht unterstützt`));
    }

    // opts: { filterObjectIds?: string[]|Set, plateObjectIds?: Set, plateIndex?: int }
    async _load3MF(url, opts = {}) {
        if (!window.JSZip) throw new Error('JSZip nicht geladen');

        const zip = await window.JSZip.loadAsync(await (await fetch(url)).arrayBuffer());

        const mapResult = await _buildObjectMap(zip);
        if (!mapResult) throw new Error('Keine .model-Datei im 3MF-Archiv gefunden');
        const { objectMap, componentRefs } = mapResult;

        // Resolve seed IDs from whichever filter option was given
        let seedIds = null;
        if (opts.filterObjectIds != null) {
            seedIds = opts.filterObjectIds instanceof Set
                ? opts.filterObjectIds
                : new Set(Array.isArray(opts.filterObjectIds) ? opts.filterObjectIds : []);
        } else if (opts.plateObjectIds != null) {
            seedIds = opts.plateObjectIds;
        } else if (opts.plateIndex != null) {
            const plates = await _parsePlatesFromZip(zip);
            if (plates) {
                const pl = plates.find(p => p.id === opts.plateIndex);
                if (pl) seedIds = pl.objectIds;
            }
        }

        // Expand seeds: assembly IDs → component mesh IDs (BFS)
        const allowed = seedIds != null ? _expandIds(seedIds, componentRefs) : null;

        const group = new THREE.Group();
        for (const [id, { el: obj }] of objectMap) {
            if (allowed !== null && !allowed.has(id)) continue;
            const type = obj.getAttribute('type') ?? 'model';
            if (type === 'support' || type === 'solidsupport') continue;
            this._addMeshFromEl(obj, group);
        }

        if (group.children.length === 0)
            throw new Error('Keine darstellbare Geometrie in der 3MF-Datei gefunden');

        this._replaceMesh(group);
    }

    _addMeshFromEl(obj, group) {
        const meshEl = _el(obj, 'mesh');
        if (!meshEl) return;
        const vertEl = _el(meshEl, 'vertices');
        const triEl  = _el(meshEl, 'triangles');
        if (!vertEl || !triEl) return;

        const vertNodes = _els(vertEl, 'vertex');
        const triNodes  = _els(triEl,  'triangle');
        if (!vertNodes.length || !triNodes.length) return;

        const verts = new Float32Array(vertNodes.length * 3);
        vertNodes.forEach((v, i) => {
            verts[i*3]   = parseFloat(v.getAttribute('x') || 0);
            verts[i*3+1] = parseFloat(v.getAttribute('y') || 0);
            verts[i*3+2] = parseFloat(v.getAttribute('z') || 0);
        });

        const pos = new Float32Array(triNodes.length * 9);
        triNodes.forEach((t, i) => {
            for (const [s, a] of [[0,'v1'],[3,'v2'],[6,'v3']]) {
                const vi = parseInt(t.getAttribute(a)) * 3;
                pos[i*9+s]   = verts[vi];
                pos[i*9+s+1] = verts[vi+1];
                pos[i*9+s+2] = verts[vi+2];
            }
        });

        const geo = new THREE.BufferGeometry();
        geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
        geo.computeVertexNormals();

        const nm = obj.getAttribute('name') ?? '';
        const cm = nm.match(/\[([#][0-9a-fA-F]{3,8})\]/);
        group.add(new THREE.Mesh(geo, new THREE.MeshPhongMaterial({
            color: cm ? new THREE.Color(cm[1]) : new THREE.Color(0x4a9eff),
            specular: 0x222222, shininess: 60,
        })));
    }

    // ── Scene helpers ─────────────────────────────────────────────────────────

    _replaceMesh(obj) {
        if (this.mesh) this.scene.remove(this.mesh);
        this.mesh = obj;

        const box    = new THREE.Box3().setFromObject(obj);
        const ctr    = box.getCenter(new THREE.Vector3());
        const sz     = box.getSize(new THREE.Vector3());
        const maxDim = Math.max(sz.x, sz.y, sz.z);
        const scale  = maxDim > 0 ? 3 / maxDim : 1;

        obj.traverse(o => {
            if (!o.isMesh || !o.geometry) return;
            o.geometry = o.geometry.clone();
            o.geometry.translate(-ctr.x, -ctr.y, -ctr.z);
            o.geometry.scale(scale, scale, scale);
        });

        this._meshTransform = { center: ctr.toArray(), scale };
        this.scene.add(obj);
        this.controls.reset();
    }

    forceResize() {
        const w = this.canvas.clientWidth;
        const h = this.canvas.clientHeight;
        if (!w || !h) return;
        this.renderer.setSize(w, h, false);
        this.camera.aspect = w / h;
        this.camera.updateProjectionMatrix();
    }

    _onResize() { this.forceResize(); }

    _animate() {
        if (!this._running) return;
        this._raf = requestAnimationFrame(() => this._animate());
        this.controls.update();
        this.renderer.render(this.scene, this.camera);
    }

    // ── Overlay ───────────────────────────────────────────────────────────────

    _makeOverlay() {
        const el = document.createElement('div');
        el.style.cssText = [
            'position:absolute','inset:0','display:none',
            'align-items:center','justify-content:center',
            'background:rgba(0,0,0,.65)','color:#fff',
            'font-size:.78rem','text-align:center',
            'padding:.5rem','white-space:pre-line',
            'pointer-events:none','border-radius:inherit',
        ].join(';');
        const parent = this.canvas.parentElement;
        if (parent) {
            if (getComputedStyle(parent).position === 'static')
                parent.style.position = 'relative';
            parent.appendChild(el);
        }
        return el;
    }

    _showOverlay(text, color = '#fff') {
        this._overlay.style.color   = color;
        this._overlay.style.display = 'flex';
        this._overlay.textContent   = text;
    }

    _hideOverlay() { this._overlay.style.display = 'none'; }
}
