<?php ob_start(); ?>

<?php $ev = filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/editor3d.js'); ?>
<script type="importmap">
{
  "imports": {
    "three":             "https://unpkg.com/three@0.167.0/build/three.module.js",
    "three/addons/":     "https://unpkg.com/three@0.167.0/examples/jsm/",
    "@app/editor3d":     "/assets/js/editor3d.js?v=<?= $ev ?>"
  }
}
</script>

<style>
/* ── Layout ─────────────────────────────────────────────────────────────── */
#ed-root {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 56px);   /* subtract header height */
    margin: -2rem -1.5rem 0;      /* bleed outside main padding */
    overflow: hidden;
}

/* toolbar */
#ed-toolbar {
    background: #16213e;
    border-bottom: 1px solid #2d3748;
    display: flex;
    align-items: center;
    gap: .4rem;
    padding: .45rem .8rem;
    flex-shrink: 0;
    flex-wrap: wrap;
}
#ed-toolbar .sep { width: 1px; height: 1.4rem; background: #2d3748; margin: 0 .2rem; }

/* body: list | canvas | props */
#ed-body {
    display: flex;
    flex: 1;
    min-height: 0;
    overflow: hidden;
}

/* object list */
#ed-list {
    width: 200px;
    flex-shrink: 0;
    border-right: 1px solid #2d3748;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: #12172a;
}
#ed-list-header {
    padding: .5rem .75rem;
    font-size: .75rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .05em;
    border-bottom: 1px solid #2d3748;
    flex-shrink: 0;
}
#ed-list-items { overflow-y: auto; flex: 1; }
.ed-item {
    display: flex;
    align-items: center;
    gap: .4rem;
    padding: .4rem .65rem;
    cursor: pointer;
    font-size: .83rem;
    color: #94a3b8;
    user-select: none;
    border-left: 2px solid transparent;
}
.ed-item:hover { background: #1a2035; color: #e2e8f0; }
.ed-item.active { background: #1e2d4f; color: #fff; border-left-color: #3b82f6; }
.ed-item .icon { font-size: .9rem; flex-shrink: 0; }
.ed-item .name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ed-item .vis-btn {
    opacity: 0;
    font-size: .8rem;
    background: none;
    border: none;
    color: #64748b;
    cursor: pointer;
    padding: 0 .1rem;
}
.ed-item:hover .vis-btn { opacity: 1; }
.ed-item .vis-btn:hover { color: #e2e8f0; }

/* viewport */
#ed-canvas-wrap {
    flex: 1;
    position: relative;
    overflow: hidden;
}
#ed-canvas {
    width: 100%;
    height: 100%;
    display: block;
    outline: none;
}
#ed-canvas-hint {
    position: absolute;
    bottom: .75rem;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0,0,0,.55);
    color: #64748b;
    font-size: .72rem;
    padding: .3rem .8rem;
    border-radius: 999px;
    pointer-events: none;
    white-space: nowrap;
}

/* properties */
#ed-props {
    width: 260px;
    flex-shrink: 0;
    border-left: 1px solid #2d3748;
    overflow-y: auto;
    background: #12172a;
    padding: .75rem;
    display: flex;
    flex-direction: column;
    gap: .85rem;
}
#ed-props.hidden { display: none; }
.prop-section { display: flex; flex-direction: column; gap: .45rem; }
.prop-title {
    font-size: .72rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.prop-row {
    display: grid;
    grid-template-columns: 20px 1fr;
    align-items: center;
    gap: .35rem;
}
.prop-axis { font-size: .72rem; color: #64748b; text-align: center; }
.prop-input {
    background: #1e2433;
    border: 1px solid #2d3748;
    border-radius: 4px;
    color: #e2e8f0;
    padding: .25rem .45rem;
    font-size: .82rem;
    width: 100%;
    -moz-appearance: textfield;
}
.prop-input::-webkit-inner-spin-button,
.prop-input::-webkit-outer-spin-button { -webkit-appearance: none; }
.prop-input:focus { outline: none; border-color: #3b82f6; }

.prop-xyz {
    display: grid;
    grid-template-columns: 14px 1fr 14px 1fr 14px 1fr;
    gap: .3rem;
    align-items: center;
}
.prop-xyz .lbl { font-size: .7rem; color: #64748b; text-align: center; }

#prop-name-input {
    background: #1e2433;
    border: 1px solid #2d3748;
    border-radius: 4px;
    color: #e2e8f0;
    padding: .3rem .5rem;
    font-size: .85rem;
    width: 100%;
}
#prop-name-input:focus { outline: none; border-color: #3b82f6; }
#prop-color {
    width: 100%;
    height: 1.9rem;
    border: 1px solid #2d3748;
    border-radius: 4px;
    cursor: pointer;
    background: none;
    padding: .1rem;
}

/* empty state */
#ed-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #334155;
    font-size: .85rem;
    gap: .5rem;
    pointer-events: none;
}

/* toolbar buttons */
.tb-btn {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    padding: .3rem .7rem;
    border-radius: 5px;
    border: none;
    cursor: pointer;
    font-size: .82rem;
    font-weight: 600;
    background: #1e2d4a;
    color: #93c5fd;
    transition: background .15s, color .15s;
}
.tb-btn:hover { background: #2d3e60; color: #fff; }
.tb-btn.active { background: #1d4ed8; color: #fff; }
.tb-btn.danger:hover { background: #7f1d1d; color: #fca5a5; }
.tb-btn.green:hover  { background: #064e3b; color: #6ee7b7; }
.tb-btn.purple:hover { background: #3b0764; color: #d8b4fe; }

#ed-scene-name {
    background: #1e2433;
    border: 1px solid #2d3748;
    border-radius: 5px;
    color: #e2e8f0;
    padding: .28rem .6rem;
    font-size: .82rem;
    width: 140px;
}
#ed-scene-name:focus { outline: none; border-color: #3b82f6; }
</style>

<div id="ed-root">

    <!-- Toolbar -->
    <div id="ed-toolbar">

        <!-- Primitives -->
        <button class="tb-btn" onclick="editor?.add('box')">&#9679; Box</button>
        <button class="tb-btn" onclick="editor?.add('sphere')">&#9679; Kugel</button>
        <button class="tb-btn" onclick="editor?.add('cylinder')">&#9679; Zylinder</button>
        <button class="tb-btn" onclick="editor?.add('cone')">&#9679; Kegel</button>
        <button class="tb-btn" onclick="editor?.add('torus')">&#9679; Torus</button>
        <button class="tb-btn" onclick="editor?.add('capsule')">&#9679; Kapsel</button>

        <div class="sep"></div>

        <!-- Transform modes -->
        <button class="tb-btn active" id="tb-translate" onclick="setMode('translate')" title="G">&#8677; Verschieben</button>
        <button class="tb-btn"        id="tb-rotate"    onclick="setMode('rotate')"    title="R">&#8635; Drehen</button>
        <button class="tb-btn"        id="tb-scale"     onclick="setMode('scale')"     title="S">&#8596; Skalieren</button>

        <div class="sep"></div>

        <!-- Object actions -->
        <button class="tb-btn" onclick="editor?.duplicate()" title="D">&#9112; Duplizieren</button>
        <button class="tb-btn danger" onclick="editor?.removeSelected()" title="Del">&#10006; Löschen</button>
        <button class="tb-btn" onclick="editor?.focusSelected()" title="F">&#9673; Fokus</button>

        <div class="sep"></div>

        <!-- Export -->
        <button class="tb-btn green" onclick="doExport()">&#8681; STL</button>
        <button class="tb-btn purple" onclick="doSaveLibrary()" id="tb-save">&#9997; Bibliothek</button>

        <div style="flex:1;"></div>

        <input id="ed-scene-name" type="text" value="Mein Modell" placeholder="Szenenname">
    </div>

    <!-- Body -->
    <div id="ed-body">

        <!-- Object list -->
        <div id="ed-list">
            <div id="ed-list-header">Objekte</div>
            <div id="ed-list-items">
                <div id="ed-empty">
                    <div style="font-size:1.8rem;">&#9643;</div>
                    <div>Noch keine Objekte</div>
                </div>
            </div>
        </div>

        <!-- Viewport -->
        <div id="ed-canvas-wrap">
            <canvas id="ed-canvas" tabindex="0"></canvas>
            <div id="ed-canvas-hint">
                G&nbsp;Verschieben &nbsp;·&nbsp; R&nbsp;Drehen &nbsp;·&nbsp; S&nbsp;Skalieren &nbsp;·&nbsp; D&nbsp;Duplizieren &nbsp;·&nbsp; F&nbsp;Fokus &nbsp;·&nbsp; Del&nbsp;Löschen
            </div>
        </div>

        <!-- Properties -->
        <div id="ed-props">
            <div id="prop-placeholder" style="color:#334155;font-size:.82rem;margin:auto;text-align:center;">
                Objekt auswählen<br>um Eigenschaften zu sehen
            </div>

            <div id="prop-content" style="display:none;flex-direction:column;gap:.85rem;">
                <!-- Name + Color -->
                <div class="prop-section">
                    <div class="prop-title">Name</div>
                    <input id="prop-name-input" type="text" oninput="onNameChange(this.value)">
                </div>
                <div class="prop-section">
                    <div class="prop-title">Farbe</div>
                    <input id="prop-color" type="color" value="#4a9eff" oninput="onColorChange(this.value)">
                </div>

                <!-- Position -->
                <div class="prop-section">
                    <div class="prop-title">Position</div>
                    <div class="prop-xyz">
                        <span class="lbl" style="color:#f87171;">X</span>
                        <input class="prop-input" type="number" step="0.1" id="px" oninput="onTransform('pos','x',this.value)">
                        <span class="lbl" style="color:#4ade80;">Y</span>
                        <input class="prop-input" type="number" step="0.1" id="py" oninput="onTransform('pos','y',this.value)">
                        <span class="lbl" style="color:#60a5fa;">Z</span>
                        <input class="prop-input" type="number" step="0.1" id="pz" oninput="onTransform('pos','z',this.value)">
                    </div>
                </div>

                <!-- Rotation -->
                <div class="prop-section">
                    <div class="prop-title">Rotation (°)</div>
                    <div class="prop-xyz">
                        <span class="lbl" style="color:#f87171;">X</span>
                        <input class="prop-input" type="number" step="1" id="rx" oninput="onTransform('rot','x',this.value)">
                        <span class="lbl" style="color:#4ade80;">Y</span>
                        <input class="prop-input" type="number" step="1" id="ry" oninput="onTransform('rot','y',this.value)">
                        <span class="lbl" style="color:#60a5fa;">Z</span>
                        <input class="prop-input" type="number" step="1" id="rz" oninput="onTransform('rot','z',this.value)">
                    </div>
                </div>

                <!-- Scale -->
                <div class="prop-section">
                    <div class="prop-title">Skalierung</div>
                    <div class="prop-xyz">
                        <span class="lbl" style="color:#f87171;">X</span>
                        <input class="prop-input" type="number" step="0.1" min="0.001" id="sx" oninput="onTransform('scale','x',this.value)">
                        <span class="lbl" style="color:#4ade80;">Y</span>
                        <input class="prop-input" type="number" step="0.1" min="0.001" id="sy" oninput="onTransform('scale','y',this.value)">
                        <span class="lbl" style="color:#60a5fa;">Z</span>
                        <input class="prop-input" type="number" step="0.1" min="0.001" id="sz" oninput="onTransform('scale','z',this.value)">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="module">
import { Editor3D } from '@app/editor3d';

// ── Init ─────────────────────────────────────────────────────────────────────
const canvas = document.getElementById('ed-canvas');

window.editor = new Editor3D(canvas, {
    onSelect: renderSelect,
    onChange: renderTransform,
});

// ── Mode buttons ──────────────────────────────────────────────────────────────
window.setMode = function(mode) {
    editor.setMode(mode);
    document.querySelectorAll('#tb-translate,#tb-rotate,#tb-scale').forEach(b => b.classList.remove('active'));
    document.getElementById('tb-' + mode).classList.add('active');
};

// ── Object list ───────────────────────────────────────────────────────────────
function renderList() {
    const container = document.getElementById('ed-list-items');
    const empty     = document.getElementById('ed-empty');
    const objs      = editor._objects;

    if (!objs.length) {
        container.innerHTML = '';
        container.appendChild(empty);
        return;
    }

    empty.remove();
    container.innerHTML = '';

    objs.forEach(obj => {
        const item = document.createElement('div');
        item.className = 'ed-item' + (editor._selected === obj ? ' active' : '');
        item.dataset.id = obj.id;
        item.innerHTML = `
            <span class="icon">${typeIcon(obj.type)}</span>
            <span class="name">${escHtml(obj.name)}</span>
            <button class="vis-btn" title="Sichtbarkeit">${obj.mesh.visible ? '👁' : '🚫'}</button>
        `;
        item.addEventListener('click', e => {
            if (e.target.classList.contains('vis-btn')) {
                editor.toggleVisible(obj);
                renderList();
            } else {
                editor.select(obj);
            }
        });
        container.appendChild(item);
    });
}

function typeIcon(t) {
    return { box:'◼', sphere:'●', cylinder:'⬬', cone:'▲', torus:'◎', capsule:'⬮' }[t] ?? '◈';
}
function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Properties ────────────────────────────────────────────────────────────────
function renderSelect(obj) {
    renderList();
    const ph = document.getElementById('prop-placeholder');
    const ct = document.getElementById('prop-content');

    if (!obj) {
        ph.style.display = 'block';
        ct.style.display = 'none';
        return;
    }
    ph.style.display = 'none';
    ct.style.display = 'flex';

    document.getElementById('prop-name-input').value = obj.name;
    document.getElementById('prop-color').value      = editor.getColor(obj);
    renderTransform(obj);
}

function renderTransform(obj) {
    if (!obj) { renderList(); return; }
    renderList();
    const t = editor.getTransform(obj);
    document.getElementById('px').value = t.px;
    document.getElementById('py').value = t.py;
    document.getElementById('pz').value = t.pz;
    document.getElementById('rx').value = t.rx;
    document.getElementById('ry').value = t.ry;
    document.getElementById('rz').value = t.rz;
    document.getElementById('sx').value = t.sx;
    document.getElementById('sy').value = t.sy;
    document.getElementById('sz').value = t.sz;
}

// ── Input handlers ────────────────────────────────────────────────────────────
window.onNameChange = function(val) {
    if (!editor._selected) return;
    editor.rename(editor._selected, val);
    renderList();
};

window.onColorChange = function(hex) {
    if (editor._selected) editor.setColor(editor._selected, hex);
};

window.onTransform = function(type, axis, val) {
    if (!editor._selected) return;
    if (type === 'pos')   editor.setPos(editor._selected,   axis, val);
    if (type === 'rot')   editor.setRot(editor._selected,   axis, val);
    if (type === 'scale') editor.setScale(editor._selected, axis, val);
};

// ── Export ────────────────────────────────────────────────────────────────────
window.doExport = function() {
    const name = document.getElementById('ed-scene-name').value.trim() || 'modell';
    editor.downloadSTL(name);
};

window.doSaveLibrary = async function() {
    const btn  = document.getElementById('tb-save');
    const name = document.getElementById('ed-scene-name').value.trim() || 'Editor-Modell';
    btn.textContent = '⏳ Speichern…';
    btn.disabled    = true;
    try {
        const res = await editor.saveToLibrary(name);
        if (res?.ok) {
            btn.textContent = '✓ Gespeichert';
            btn.style.color = '#4ade80';
            setTimeout(() => {
                btn.textContent = '✏ Bibliothek';
                btn.style.color = '';
                btn.disabled    = false;
            }, 2500);
        } else {
            throw new Error('Fehler');
        }
    } catch {
        btn.textContent = '✗ Fehler';
        btn.style.color = '#f87171';
        setTimeout(() => {
            btn.textContent = '✏ Bibliothek';
            btn.style.color = '';
            btn.disabled    = false;
        }, 2500);
    }
};
</script>

<?php
// Use a custom base layout without the <main> wrapper so we can go full-height
$content = ob_get_clean();
// Inline the base template with custom wrapper
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HEK 3D — Editor</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body   { font-family: system-ui, sans-serif; background: #0f1117; color: #e2e8f0; height: 100vh; overflow: hidden; }
        header { background: #1a1a2e; border-bottom: 1px solid #2d3748; padding: .9rem 2rem;
                 display: flex; align-items: center; gap: 1.5rem; flex-shrink: 0; height: 56px; }
        header h1 { font-size: 1.1rem; font-weight: 700; letter-spacing: .03em; color: #fff; }
        header nav { display: flex; gap: .2rem; }
        header nav a { color: #94a3b8; text-decoration: none; padding: .4rem .75rem;
                       border-radius: 5px; font-size: .9rem; transition: background .15s, color .15s; }
        header nav a:hover, header nav a.active { background: #2d3748; color: #fff; }
    </style>
</head>
<body>
<header>
    <h1>HEK 3D</h1>
    <nav>
        <?php $cur = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); ?>
        <a href="/"        class="<?= $cur === '/'       ? 'active' : '' ?>">Jobs</a>
        <a href="/jobs/new" class="<?= $cur === '/jobs/new' ? 'active' : '' ?>">Neuer Job</a>
        <a href="/magnet"  class="<?= str_starts_with($cur, '/magnet') ? 'active' : '' ?>"
           style="<?= str_starts_with($cur, '/magnet') ? '' : 'color:#a78bfa;' ?>">Magnetlöcher</a>
        <a href="/models"  class="<?= str_starts_with($cur, '/models')  ? 'active' : '' ?>">Bibliothek</a>
        <a href="/convert" class="<?= str_starts_with($cur, '/convert') ? 'active' : '' ?>"
           style="<?= str_starts_with($cur, '/convert') ? '' : 'color:#34d399;' ?>">SVG Konverter</a>
        <a href="/editor"  class="<?= str_starts_with($cur, '/editor')  ? 'active' : '' ?>"
           style="<?= str_starts_with($cur, '/editor')  ? '' : 'color:#fb923c;' ?>">3D Editor</a>
    </nav>
</header>
<?= $content ?>
</body>
</html>
