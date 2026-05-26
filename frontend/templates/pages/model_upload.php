<?php ob_start(); ?>

<script src="https://unpkg.com/jszip@3.10.1/dist/jszip.min.js"></script>
<?php $vv = filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/viewer.js'); ?>
<script type="importmap">
{
  "imports": {
    "three":         "https://unpkg.com/three@0.167.0/build/three.module.js",
    "three/addons/": "https://unpkg.com/three@0.167.0/examples/jsm/",
    "@app/viewer":   "/assets/js/viewer.js?v=<?= $vv ?>"
  }
}
</script>

<style>
.upload-layout { display:grid; grid-template-columns:1fr 280px; gap:1.5rem; align-items:start; }
@media(max-width:760px){ .upload-layout { grid-template-columns:1fr; } }

.drop-zone { border:2px dashed #374151; border-radius:10px; padding:2.5rem 1.5rem;
             text-align:center; cursor:pointer; transition:border-color .2s, background .2s;
             background:#111827; }
.drop-zone:hover, .drop-zone.drag-over { border-color:#60a5fa; background:#0d1520; }
.drop-zone input { display:none; }
.drop-zone .icon { font-size:2.2rem; margin-bottom:.5rem; }
.drop-zone .hint { font-size:.82rem; color:#64748b; margin-top:.3rem; }
#file-name { margin-top:.5rem; color:#60a5fa; font-size:.85rem; }

/* Structure tree */
#structure-section { display:none; }
.struct-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:.75rem; }
.struct-group { margin-bottom:.75rem; }
.struct-plate-header {
    display:flex; align-items:center; gap:.6rem;
    padding:.4rem .65rem; background:#16213e; border-radius:6px;
    margin-bottom:.4rem; font-size:.85rem; font-weight:600; color:#cbd5e1;
}
.struct-plate-thumb { width:44px; height:33px; object-fit:contain; background:#fff; border-radius:3px; flex-shrink:0; }
.struct-objects { display:flex; flex-direction:column; gap:.3rem; padding:0 .15rem; }
.struct-obj {
    display:flex; align-items:center; gap:.6rem;
    padding:.35rem .65rem; border-radius:5px; border:1px solid #2d3748;
    background:#111827; font-size:.82rem; cursor:pointer; user-select:none;
    transition:border-color .15s;
}
.struct-obj:hover { border-color:#4b5563; }
.struct-obj.support-obj { opacity:.5; }
.struct-obj input[type=checkbox] { cursor:pointer; accent-color:#3b82f6; flex-shrink:0; }
.struct-obj .obj-name { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#e2e8f0; }
.struct-obj .type-badge {
    font-size:.7rem; padding:.15rem .45rem; border-radius:3px;
    background:#1e3a5f; color:#60a5fa; white-space:nowrap; flex-shrink:0;
}
.struct-obj.support-obj .type-badge { background:#451a03; color:#fb923c; }

/* 3D preview */
#preview-section { display:none; margin-top:0; }
#preview-canvas  { width:100%; height:340px; border-radius:8px; border:1px solid #2d3748; background:#111827; display:block; }
</style>

<?php if (!empty($error)): ?>
    <div style="background:#450a0a;color:#f87171;padding:.75rem 1rem;border-radius:6px;margin-bottom:1rem;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<h2 style="margin-bottom:1.2rem;font-size:1.3rem;">3D-Modell hochladen</h2>

<div class="upload-layout">
    <!-- Left: drop zone + structure + preview -->
    <div style="display:flex;flex-direction:column;gap:1.2rem;">

        <div class="card">
            <div class="drop-zone" id="drop-zone">
                <div class="icon">📂</div>
                <div>Datei hier ablegen oder <strong style="color:#60a5fa;">klicken</strong></div>
                <div class="hint">STL · OBJ · PLY · GLB · GLTF · 3MF</div>
                <div id="file-name"></div>
                <input type="file" id="file-input" accept=".stl,.obj,.ply,.glb,.gltf,.3mf">
            </div>
        </div>

        <!-- 3MF Structure tree (replaces old plate picker) -->
        <div class="card" id="structure-section">
            <div class="struct-toolbar">
                <h3 style="font-size:1rem;">3MF Struktur</h3>
                <div style="display:flex;gap:.4rem;">
                    <button type="button" class="btn btn-ghost" id="sel-all"
                            style="font-size:.78rem;padding:.25rem .65rem;">Alle</button>
                    <button type="button" class="btn btn-ghost" id="sel-none"
                            style="font-size:.78rem;padding:.25rem .65rem;">Keine</button>
                </div>
            </div>
            <div id="structure-tree"></div>
        </div>

        <!-- 3D Preview -->
        <div class="card" id="preview-section" style="padding:1rem;">
            <h3 style="font-size:.95rem;margin-bottom:.65rem;">Vorschau</h3>
            <canvas id="preview-canvas"></canvas>
        </div>

    </div>

    <!-- Right: info + submit -->
    <div style="display:flex;flex-direction:column;gap:1rem;">
        <div class="card">
            <h3 style="font-size:1rem;margin-bottom:.85rem;">Hochladen</h3>
            <form method="POST" action="/models/new" enctype="multipart/form-data"
                  id="upload-form" style="display:flex;flex-direction:column;gap:.9rem;">
                <input type="file"   name="model"          id="f-model"          style="display:none" accept=".stl,.obj,.ply,.glb,.gltf,.3mf">
                <input type="hidden" name="filter_objects"  id="f-filter-objects" value="">

                <div>
                    <label style="display:block;font-size:.82rem;color:#94a3b8;margin-bottom:.35rem;">Name <span style="color:#4b5563;">(optional, wird als Dateiname verwendet)</span></label>
                    <input type="text" name="name" id="f-name" placeholder="z.B. Schlüsselanhänger-HEK"
                           style="width:100%;padding:.5rem .7rem;background:#111827;border:1px solid #374151;
                                  border-radius:6px;color:#e2e8f0;font-size:.875rem;box-sizing:border-box;">
                </div>

                <div id="submit-hints" style="font-size:.82rem;color:#64748b;line-height:1.6;">
                    <div id="hint-file" style="color:#f87171;">✗ Datei wählen</div>
                    <div id="hint-struct" style="display:none;color:#94a3b8;">– Objekte wählen</div>
                </div>

                <button type="submit" id="submit-btn" class="btn btn-primary" disabled>
                    Hochladen
                </button>
            </form>
        </div>

        <div class="card" style="font-size:.82rem;color:#64748b;line-height:1.7;">
            <strong style="color:#94a3b8;">Unterstützte Formate</strong><br>
            STL, OBJ, PLY, GLB, GLTF, 3MF<br><br>
            <strong style="color:#94a3b8;">3MF Struktur</strong><br>
            Bei 3MF-Dateien wird die enthaltene Objektstruktur angezeigt. Du kannst einzelne
            Objekte oder Druckplatten (BambuStudio) gezielt auswählen.
        </div>

        <a href="/models" class="btn btn-ghost" style="justify-content:center;">Abbrechen</a>
    </div>
</div>

<script type="module">
import { ModelViewer, parseStructure3MF } from '@app/viewer';

const dropZone       = document.getElementById('drop-zone');
const fileInput      = document.getElementById('file-input');
const fModel         = document.getElementById('f-model');
const fFilterObjects = document.getElementById('f-filter-objects');
const fName          = document.getElementById('f-name');
const submitBtn      = document.getElementById('submit-btn');
const hintFile       = document.getElementById('hint-file');
const hintStruct     = document.getElementById('hint-struct');
const structSec      = document.getElementById('structure-section');
const structTree     = document.getElementById('structure-tree');
const previewSec     = document.getElementById('preview-section');

let viewer    = null;
let objectUrl = null;

// ── Drop zone ──────────────────────────────────────────────────────────────
dropZone.addEventListener('click', () => fileInput.click());
dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    if (e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]);
});
fileInput.addEventListener('change', () => { if (fileInput.files[0]) handleFile(fileInput.files[0]); });

// ── File handler ───────────────────────────────────────────────────────────
async function handleFile(file) {
    const ext = file.name.split('.').pop().toLowerCase();

    const dt = new DataTransfer();
    dt.items.add(file);
    fModel.files = dt.files;

    document.getElementById('file-name').textContent = `${file.name} (${(file.size / 1024 / 1024).toFixed(1)} MB)`;
    hintFile.style.color = '#4ade80';
    hintFile.textContent = '✓ ' + file.name;

    if (!fName.value.trim()) {
        fName.value = file.name.replace(/\.[^.]+$/, '');
    }

    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = URL.createObjectURL(file);

    structSec.style.display   = 'none';
    previewSec.style.display  = 'none';
    structTree.innerHTML      = '';
    hintStruct.style.display  = 'none';
    fFilterObjects.value      = '';

    if (ext === '3mf') {
        const structure = await parseStructure3MF(objectUrl);
        if (structure) {
            buildStructureTree(structure);
            structSec.style.display  = 'block';
            hintStruct.style.display = 'block';
            hintStruct.style.color   = '#4ade80';
            hintStruct.textContent   = '✓ Struktur geladen';
            // filter_objects is set by buildStructureTree defaults
        } else {
            // Fallback: no structure info, load all
            loadPreview(ext, null);
        }
    } else {
        loadPreview(ext, null);
    }

    submitBtn.disabled = false;
}

// ── Structure tree builder ─────────────────────────────────────────────────
function buildStructureTree(structure) {
    const { objects, plates } = structure;
    structTree.innerHTML = '';

    // Top-level objects to display. If there are no isTopLevel objects with
    // geometry or components, fall back to all objects with geometry.
    let displayObjs = objects.filter(o => o.isTopLevel && (o.hasGeometry || o.components.length > 0));
    if (!displayObjs.length) displayObjs = objects.filter(o => o.hasGeometry);

    if (plates && plates.length > 0) {
        // Group by plate
        const allPlateIds = new Set(plates.flatMap(p => [...p.objectIds]));

        for (const plate of plates) {
            const plateObjs = displayObjs.filter(o => plate.objectIds.has(o.id));
            if (!plateObjs.length) continue;

            const group  = document.createElement('div');
            group.className = 'struct-group';

            const header = document.createElement('div');
            header.className = 'struct-plate-header';
            if (plate.thumbnailUrl) {
                const img = document.createElement('img');
                img.className = 'struct-plate-thumb';
                img.src = plate.thumbnailUrl;
                img.alt = plate.name;
                header.appendChild(img);
            } else {
                const ph = document.createElement('span');
                ph.style.cssText = 'font-size:1.4rem;flex-shrink:0;';
                ph.textContent   = '🖨';
                header.appendChild(ph);
            }
            const lbl = document.createElement('span');
            lbl.textContent = plate.name;
            header.appendChild(lbl);
            group.appendChild(header);

            const list = document.createElement('div');
            list.className = 'struct-objects';
            plateObjs.forEach(obj => list.appendChild(makeObjectRow(obj)));
            group.appendChild(list);
            structTree.appendChild(group);
        }

        // Unassigned objects
        const unassigned = displayObjs.filter(o => !allPlateIds.has(o.id));
        if (unassigned.length) {
            const group  = document.createElement('div');
            group.className = 'struct-group';
            const header = document.createElement('div');
            header.className = 'struct-plate-header';
            header.innerHTML = '<span style="font-size:1rem;flex-shrink:0;">📦</span><span>Nicht zugewiesen</span>';
            group.appendChild(header);
            const list = document.createElement('div');
            list.className = 'struct-objects';
            unassigned.forEach(obj => list.appendChild(makeObjectRow(obj)));
            group.appendChild(list);
            structTree.appendChild(group);
        }
    } else {
        // No plates – flat list
        const list = document.createElement('div');
        list.className = 'struct-objects';
        displayObjs.forEach(obj => list.appendChild(makeObjectRow(obj)));
        structTree.appendChild(list);
    }

    syncFilterObjects();
    loadPreviewFromSelection();
}

function makeObjectRow(obj) {
    const isSupport = obj.type === 'support' || obj.type === 'solidsupport';
    const row = document.createElement('label');
    row.className = 'struct-obj' + (isSupport ? ' support-obj' : '');

    const cb     = document.createElement('input');
    cb.type      = 'checkbox';
    cb.value     = obj.id;
    cb.checked   = !isSupport;
    cb.addEventListener('change', () => { syncFilterObjects(); loadPreviewFromSelection(); });
    row.appendChild(cb);

    const nameEl = document.createElement('span');
    nameEl.className   = 'obj-name';
    nameEl.textContent = obj.name;
    row.appendChild(nameEl);

    const badge = document.createElement('span');
    badge.className   = 'type-badge';
    badge.textContent = isSupport ? 'support'
        : obj.hasGeometry ? 'mesh'
        : `${obj.components.length} Teil${obj.components.length !== 1 ? 'e' : ''}`;
    row.appendChild(badge);

    return row;
}

function syncFilterObjects() {
    const ids = getCheckedIds();
    fFilterObjects.value = ids.length ? JSON.stringify(ids) : '';
}

function getCheckedIds() {
    return Array.from(structTree.querySelectorAll('input[type=checkbox]:checked'))
        .map(cb => cb.value);
}

// ── Sel-all / Sel-none ─────────────────────────────────────────────────────
document.getElementById('sel-all').addEventListener('click', () => {
    structTree.querySelectorAll('input[type=checkbox]').forEach(cb => { cb.checked = true; });
    syncFilterObjects();
    loadPreviewFromSelection();
});
document.getElementById('sel-none').addEventListener('click', () => {
    structTree.querySelectorAll('input[type=checkbox]').forEach(cb => { cb.checked = false; });
    syncFilterObjects();
    loadPreviewFromSelection();
});

// ── 3D Preview ─────────────────────────────────────────────────────────────
function loadPreviewFromSelection() {
    const ids = getCheckedIds();
    loadPreview('3mf', ids.length ? new Set(ids) : null);
}

function loadPreview(ext, filterObjectIds) {
    previewSec.style.display = 'block';
    if (!viewer) viewer = new ModelViewer(document.getElementById('preview-canvas'));
    const opts = filterObjectIds ? { filterObjectIds } : {};
    viewer.loadModel(objectUrl, ext, opts).catch(err => console.warn('Preview error:', err));
}
</script>

<?php $content = ob_get_clean();
require __DIR__ . '/../layouts/base.php'; ?>
