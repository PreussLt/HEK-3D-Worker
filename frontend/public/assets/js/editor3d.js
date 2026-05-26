import * as THREE from 'three';
import { OrbitControls }     from 'three/addons/controls/OrbitControls.js';
import { TransformControls } from 'three/addons/controls/TransformControls.js';
import { STLExporter }       from 'three/addons/exporters/STLExporter.js';

export class Editor3D {
    constructor(canvas, { onSelect = () => {}, onChange = () => {} } = {}) {
        this._canvas    = canvas;
        this._objects   = [];      // [{ id, name, type, mesh }]
        this._selected  = null;
        this._uid       = 0;
        this._running   = true;
        this._onSelect  = onSelect;
        this._onChange  = onChange;

        this._initRenderer();
        this._initScene();
        this._initControls();
        this._initPicking();
        this._bindKeys();
        this._animate();
    }

    // ── Init ──────────────────────────────────────────────────────────────────

    _initRenderer() {
        const w = this._canvas.clientWidth  || 900;
        const h = this._canvas.clientHeight || 600;

        this._ren = new THREE.WebGLRenderer({ canvas: this._canvas, antialias: true });
        this._ren.setSize(w, h, false);
        this._ren.setPixelRatio(Math.min(devicePixelRatio, 2));
        this._ren.shadowMap.enabled = true;
        this._ren.shadowMap.type    = THREE.PCFSoftShadowMap;

        this._cam = new THREE.PerspectiveCamera(50, w / h, 0.01, 10000);
        this._cam.position.set(6, 5, 9);

        new ResizeObserver(() => {
            const w = this._canvas.clientWidth, h = this._canvas.clientHeight;
            if (!w || !h) return;
            this._ren.setSize(w, h, false);
            this._cam.aspect = w / h;
            this._cam.updateProjectionMatrix();
        }).observe(this._canvas);
    }

    _initScene() {
        this._scene = new THREE.Scene();
        this._scene.background = new THREE.Color(0x161b2e);
        this._scene.fog = new THREE.FogExp2(0x161b2e, 0.018);

        // Lights
        this._scene.add(new THREE.AmbientLight(0xffffff, 0.55));
        const sun = new THREE.DirectionalLight(0xffffff, 0.9);
        sun.position.set(10, 18, 14);
        sun.castShadow = true;
        sun.shadow.mapSize.setScalar(2048);
        this._scene.add(sun);
        const fill = new THREE.DirectionalLight(0x4466aa, 0.25);
        fill.position.set(-8, -4, -10);
        this._scene.add(fill);

        // Grid
        const grid = new THREE.GridHelper(30, 30, 0x2d3748, 0x232c3f);
        this._scene.add(grid);

        // Axes
        this._scene.add(new THREE.AxesHelper(1.5));

        // Shadow catcher
        const floor = new THREE.Mesh(
            new THREE.PlaneGeometry(60, 60),
            new THREE.ShadowMaterial({ opacity: 0.2 })
        );
        floor.rotation.x = -Math.PI / 2;
        floor.receiveShadow = true;
        this._scene.add(floor);
    }

    _initControls() {
        this._orbit = new OrbitControls(this._cam, this._ren.domElement);
        this._orbit.enableDamping  = true;
        this._orbit.dampingFactor  = 0.06;
        this._orbit.target.set(0, 0.5, 0);

        this._tc = new TransformControls(this._cam, this._ren.domElement);
        this._tc.setMode('translate');
        this._tc.addEventListener('dragging-changed', e => {
            this._orbit.enabled = !e.value;
        });
        this._tc.addEventListener('change', () => {
            if (this._selected) this._onChange(this._selected);
        });
        this._scene.add(this._tc);
    }

    _initPicking() {
        this._rc  = new THREE.Raycaster();
        this._ptd = null;
        const dom = this._ren.domElement;
        dom.addEventListener('pointerdown', e => { this._ptd = [e.clientX, e.clientY]; });
        dom.addEventListener('pointerup',   e => {
            if (!this._ptd) return;
            const moved = Math.hypot(e.clientX - this._ptd[0], e.clientY - this._ptd[1]);
            this._ptd = null;
            if (moved < 5) this._pick(e);
        });
    }

    _animate() {
        if (!this._running) return;
        requestAnimationFrame(() => this._animate());
        this._orbit.update();
        this._ren.render(this._scene, this._cam);
    }

    // ── Picking ───────────────────────────────────────────────────────────────

    _pick(e) {
        if (this._tc.dragging) return;
        const rect = this._canvas.getBoundingClientRect();
        this._rc.setFromCamera({
            x:  ((e.clientX - rect.left) / rect.width)  *  2 - 1,
            y: -((e.clientY - rect.top)  / rect.height) *  2 + 1,
        }, this._cam);
        const hits = this._rc.intersectObjects(
            this._objects.filter(o => o.mesh.visible).map(o => o.mesh)
        );
        const hit = hits[0]?.object ?? null;
        this.select(hit ? (this._objects.find(o => o.mesh === hit) ?? null) : null);
    }

    // ── Keyboard ──────────────────────────────────────────────────────────────

    _bindKeys() {
        window.addEventListener('keydown', e => {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
            switch (e.key) {
                case 'g': case 'G': this.setMode('translate'); break;
                case 'r': case 'R': this.setMode('rotate');    break;
                case 's': case 'S': this.setMode('scale');     break;
                case 'd': case 'D': this.duplicate();          break;
                case 'f': case 'F': this.focusSelected();      break;
                case 'Escape':      this.select(null);         break;
                case 'Delete': case 'Backspace':
                    e.preventDefault(); this.removeSelected(); break;
            }
        });
    }

    // ── Object management ─────────────────────────────────────────────────────

    add(type) {
        const id   = ++this._uid;
        const name = type[0].toUpperCase() + type.slice(1) + '_' + id;
        const S    = 32;
        const makers = {
            box:       () => new THREE.BoxGeometry(1, 1, 1),
            sphere:    () => new THREE.SphereGeometry(0.6, S, 16),
            cylinder:  () => new THREE.CylinderGeometry(0.5, 0.5, 1.2, S),
            cone:      () => new THREE.ConeGeometry(0.55, 1.2, S),
            torus:     () => new THREE.TorusGeometry(0.55, 0.2, 16, S),
            capsule:   () => new THREE.CapsuleGeometry(0.4, 0.8, 8, S),
        };
        const geo  = (makers[type] ?? makers.box)();
        const mat  = new THREE.MeshPhongMaterial({
            color: 0x4a9eff, specular: 0x222244, shininess: 65,
        });
        const mesh = new THREE.Mesh(geo, mat);
        mesh.castShadow = mesh.receiveShadow = true;
        // Sit on grid
        const h = new THREE.Box3().setFromObject(mesh).getSize(new THREE.Vector3()).y;
        mesh.position.y = h / 2;

        this._scene.add(mesh);
        const obj = { id, name, type, mesh };
        this._objects.push(obj);
        this.select(obj);
        this._onChange(null);
        return obj;
    }

    removeSelected() { if (this._selected) this.remove(this._selected); }

    remove(obj) {
        if (this._selected === obj) { this._tc.detach(); this._selected = null; this._onSelect(null); }
        this._scene.remove(obj.mesh);
        obj.mesh.geometry.dispose();
        obj.mesh.material.dispose();
        this._objects = this._objects.filter(o => o !== obj);
        this._onChange(null);
    }

    duplicate() {
        if (!this._selected) return;
        const s    = this._selected;
        const id   = ++this._uid;
        const mesh = new THREE.Mesh(s.mesh.geometry.clone(), s.mesh.material.clone());
        mesh.castShadow = mesh.receiveShadow = true;
        mesh.position.copy(s.mesh.position).x += 1.2;
        mesh.rotation.copy(s.mesh.rotation);
        mesh.scale.copy(s.mesh.scale);
        this._scene.add(mesh);
        const obj = { id, name: s.name + '_K', type: s.type, mesh };
        this._objects.push(obj);
        this.select(obj);
        this._onChange(null);
    }

    select(obj) {
        if (this._selected) this._selected.mesh.material.emissive?.setHex(0);
        this._tc.detach();
        this._selected = obj;
        if (obj) {
            obj.mesh.material.emissive?.setHex(0x0d1f3c);
            this._tc.attach(obj.mesh);
        }
        this._onSelect(obj);
    }

    setMode(mode) { this._tc.setMode(mode); }

    setColor(obj, hex)      { obj.mesh.material.color.set(hex); }
    setPos(obj, a, v)       { obj.mesh.position[a] = +v; }
    setRot(obj, a, deg)     { obj.mesh.rotation[a] = THREE.MathUtils.degToRad(+deg); }
    setScale(obj, a, v)     { obj.mesh.scale[a] = Math.max(0.001, +v); }
    toggleVisible(obj)      { obj.mesh.visible = !obj.mesh.visible; }
    rename(obj, name)       { obj.name = name; }

    getColor(obj)  { return '#' + obj.mesh.material.color.getHexString(); }
    getTransform(obj) {
        const { position: p, rotation: r, scale: s } = obj.mesh;
        const d = v => +THREE.MathUtils.radToDeg(v).toFixed(2);
        const f = v => +v.toFixed(4);
        return {
            px: f(p.x), py: f(p.y), pz: f(p.z),
            rx: d(r.x), ry: d(r.y), rz: d(r.z),
            sx: f(s.x), sy: f(s.y), sz: f(s.z),
        };
    }

    focusSelected() {
        if (!this._selected) return;
        const c = new THREE.Box3().setFromObject(this._selected.mesh).getCenter(new THREE.Vector3());
        this._orbit.target.copy(c);
    }

    // ── Export ────────────────────────────────────────────────────────────────

    _buildExportGroup() {
        const grp = new THREE.Group();
        this._objects.filter(o => o.mesh.visible).forEach(o => {
            const m = o.mesh.clone();
            m.updateWorldMatrix(true, false);
            grp.add(m);
        });
        return grp;
    }

    downloadSTL(name = 'modell') {
        const data = new STLExporter().parse(this._buildExportGroup(), { binary: true });
        const a    = document.createElement('a');
        a.href     = URL.createObjectURL(new Blob([data]));
        a.download = name + '.stl';
        a.click();
    }

    async saveToLibrary(name = 'Editor-Modell') {
        const data = new STLExporter().parse(this._buildExportGroup(), { binary: true });
        const fd   = new FormData();
        fd.append('model', new Blob([data], { type: 'application/octet-stream' }), name + '.stl');
        fd.append('name',  name);
        const r = await fetch('/editor/save', { method: 'POST', body: fd });
        return r.ok ? r.json() : null;
    }

    destroy() { this._running = false; this._ren.dispose(); }
}
