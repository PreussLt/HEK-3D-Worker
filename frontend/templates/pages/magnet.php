<?php ob_start(); ?>

<script src="https://unpkg.com/jszip@3.10.1/dist/jszip.min.js"></script>
<?php
$vv = filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/viewer.js');
$ve = filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/magnet-editor.js');
?>
<script type="importmap">
{
  "imports": {
    "three":              "https://unpkg.com/three@0.167.0/build/three.module.js",
    "three/addons/":      "https://unpkg.com/three@0.167.0/examples/jsm/",
    "@app/viewer":        "/assets/js/viewer.js?v=<?= $vv ?>",
    "@app/magnet-editor": "/assets/js/magnet-editor.js?v=<?= $ve ?>"
  }
}
</script>

<style>
.mag-layout    { display:grid; grid-template-columns:1fr 300px; gap:1.5rem; align-items:start; }
@media(max-width:820px){ .mag-layout { grid-template-columns:1fr; } }

/* Model picker */
.picker-grid   { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:.65rem; margin-top:.75rem; }
.picker-card   { border:2px solid #2d3748; border-radius:8px; overflow:hidden; cursor:pointer;
                 transition:border-color .18s,box-shadow .18s; background:#16213e; }
.picker-card:hover    { border-color:#60a5fa; }
.picker-card.selected { border-color:#a78bfa; box-shadow:0 0 0 3px rgba(167,139,250,.3); }
.picker-card canvas   { width:100%; height:80px; display:block; pointer-events:none; }
.picker-card .label   { padding:.35rem .55rem; font-size:.76rem; font-weight:600;
                        white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* Editor section */
#editor-section         { display:none; margin-top:1.2rem; }
#editor-section.visible { display:block; }

.editor-wrap   { display:grid; grid-template-columns:1fr 240px; gap:1rem; align-items:start; }
@media(max-width:820px){ .editor-wrap { grid-template-columns:1fr; } }

#mag-canvas    { width:100%; height:440px; display:block; border-radius:8px;
                 border:1px solid #2d3748; background:#111827; cursor:crosshair; }

/* Controls panel */
.ctrl-panel    { background:#1e2433; border:1px solid #2d3748; border-radius:8px; padding:1rem;
                 display:flex; flex-direction:column; gap:.85rem; }
.ctrl-group    { display:flex; flex-direction:column; gap:.3rem; }
.ctrl-label    { font-size:.78rem; font-weight:700; color:#94a3b8;
                 display:flex; justify-content:space-between; align-items:center; }
.hint-box      { background:#1e1a3f; border:1px solid #4c1d95; border-radius:6px;
                 padding:.6rem .8rem; font-size:.78rem; color:#c4b5fd; line-height:1.55; }

input[type=number] {
    width:100%; background:#111827; border:1px solid #374151; border-radius:6px;
    color:#e2e8f0; padding:.45rem .7rem; font-size:.95rem; font-weight:700; }
input[type=number]:focus { outline:2px solid #a78bfa; border-color:transparent; }

/* n_magnets stepper */
.stepper       { display:flex; align-items:center; gap:.5rem; }
.stepper button{ width:2rem; height:2rem; border-radius:5px; border:1px solid #374151;
                 background:#2d3748; color:#e2e8f0; font-size:1.1rem; cursor:pointer;
                 display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.stepper button:hover { background:#4b5563; }
.stepper .val  { font-size:1.3rem; font-weight:800; color:#a78bfa; min-width:2.5rem; text-align:center; }

/* Pattern selector */
.pattern-grid  { display:grid; grid-template-columns:repeat(3,1fr); gap:.35rem; margin-top:.3rem; }
.pat-btn       { padding:.38rem .3rem; border-radius:5px; border:1px solid #374151;
                 background:#1e2433; color:#94a3b8; font-size:.72rem; font-weight:600;
                 cursor:pointer; text-align:center; transition:background .15s,border-color .15s,color .15s; }
.pat-btn:hover            { background:#2d3748; color:#e2e8f0; }
.pat-btn.selected         { background:#3b1f6e; border-color:#a78bfa; color:#c4b5fd; }

/* Face status */
#face-status   { font-size:.78rem; line-height:1.5; }
.face-none     { color:#4b5563; }
.face-ok       { color:#4ade80; }
.face-warn     { color:#fb923c; }

/* Sidebar */
.sidebar       { display:flex; flex-direction:column; gap:1rem; }
</style>

<h2 style="margin-bottom:1.2rem;font-size:1.3rem;">Magnetlöcher bohren</h2>

<div class="mag-layout">
    <!-- Left: picker + interactive editor -->
    <div>
        <!-- Step 1: Model picker -->
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <h3 style="font-size:1rem;">1. 3D-Modell wählen</h3>
                <a href="/models/new" class="btn btn-ghost" style="font-size:.8rem;">+ Hochladen</a>
            </div>

            <?php if (empty($models)): ?>
                <p style="color:#4b5563;margin-top:.75rem;">
                    Noch keine Modelle.
                    <a href="/models/new" style="color:#60a5fa;">Modell hochladen →</a>
                </p>
            <?php else: ?>
            <div class="picker-grid" id="picker-grid">
                <?php foreach ($models as $m): ?>
                <div class="picker-card"
                     data-id="<?= htmlspecialchars($m['id']) ?>"
                     data-key="<?= htmlspecialchars($m['storage_key']) ?>"
                     data-fmt="<?= htmlspecialchars($m['format']) ?>"
                     title="<?= htmlspecialchars($m['filename']) ?>">
                    <canvas></canvas>
                    <div class="label"><?= htmlspecialchars($m['filename']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Step 2: Editor (hidden until model selected) -->
        <div id="editor-section">
            <div class="card" style="padding:1rem 1rem .85rem;">
                <h3 style="font-size:1rem;margin-bottom:.75rem;">2. Fläche anklicken · Anzahl wählen</h3>
                <div class="editor-wrap">
                    <canvas id="mag-canvas"></canvas>

                    <div class="ctrl-panel">
                        <div class="hint-box">
                            <strong>Fläche auswählen:</strong><br>
                            Klicke auf eine <em>ebene Fläche</em> des Modells.<br>
                            Einzelne Löcher können per <em>Drag</em> verschoben werden.
                        </div>

                        <!-- Anzahl Löcher -->
                        <div class="ctrl-group">
                            <span class="ctrl-label">Anzahl Löcher</span>
                            <div class="stepper">
                                <button id="n-minus">−</button>
                                <span class="val" id="n-val">4</span>
                                <button id="n-plus">+</button>
                            </div>
                        </div>

                        <!-- Anordnung -->
                        <div class="ctrl-group">
                            <span class="ctrl-label">Anordnung</span>
                            <div class="pattern-grid" id="pattern-btns">
                                <button class="pat-btn selected" data-pattern="perimeter">Umfang</button>
                                <button class="pat-btn" data-pattern="line_h">Linie —</button>
                                <button class="pat-btn" data-pattern="line_v">Linie |</button>
                                <button class="pat-btn" data-pattern="diagonal">Diagonal ↘</button>
                                <button class="pat-btn" data-pattern="diagonal2">Diagonal ↗</button>
                                <button class="pat-btn" data-pattern="circle">Kreis ○</button>
                                <button class="pat-btn" data-pattern="grid">Raster ⊞</button>
                            </div>
                        </div>

                        <!-- Durchmesser -->
                        <div class="ctrl-group">
                            <span class="ctrl-label">Durchmesser (mm)</span>
                            <input type="number" id="inp-diam" min="1" max="50" step="0.5" value="6">
                            <span style="font-size:.71rem;color:#64748b;">z.B. 6 mm, 8 mm, 10 mm</span>
                        </div>

                        <!-- Tiefe -->
                        <div class="ctrl-group">
                            <span class="ctrl-label">Tiefe / Länge (mm)</span>
                            <input type="number" id="inp-len" min="0.5" max="50" step="0.5" value="2">
                            <span style="font-size:.71rem;color:#64748b;">Höhe des Magneten</span>
                        </div>

                        <!-- Status -->
                        <div id="face-status" class="face-none">
                            Noch keine Fläche gewählt.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: submit -->
    <div class="sidebar">
        <div class="card">
            <h3 style="font-size:1rem;margin-bottom:.9rem;">3. Job starten</h3>

            <form id="mag-form" method="POST" action="/magnet/new"
                  style="display:flex;flex-direction:column;gap:.85rem;">
                <input type="hidden" name="model_id"        id="h-model-id">
                <input type="hidden" name="magnet_diameter" id="h-diam"  value="6">
                <input type="hidden" name="magnet_length"   id="h-len"   value="2">
                <input type="hidden" name="n_magnets"       id="h-n"     value="4">
                <input type="hidden" name="face_selection"  id="h-face"  value="">

                <div style="font-size:.82rem;line-height:1.7;">
                    <div id="hint-model" style="color:#f87171;">✗ Modell wählen</div>
                    <div id="hint-face"  style="color:#94a3b8;">– Fläche (optional)</div>
                </div>

                <button type="submit" id="submit-btn" class="btn btn-primary"
                        style="background:#7c3aed;" disabled>
                    Magnetlöcher berechnen
                </button>
            </form>
        </div>

        <div class="card" style="font-size:.8rem;color:#64748b;line-height:1.8;">
            <strong style="color:#94a3b8;display:block;margin-bottom:.3rem;">Ergebnis</strong>
            <span style="color:#a78bfa;">■</span> Modell mit Bohrlöchern (.stl)<br>
            <span style="color:#34d399;">■</span> Bohrschablone (.stl)<br>
            <hr style="border-color:#2d3748;margin:.55rem 0;">
            <strong style="color:#94a3b8;">Schablone:</strong><br>
            Drucken → auf die Fläche legen → Bohrer (= Ø Magnet) durch die Löcher.
        </div>
    </div>
</div>

<script type="module">
import { ModelViewer }  from '@app/viewer';
import { MagnetEditor } from '@app/magnet-editor';

let selectedModel = null;
let editor        = null;
let editorReady   = false;

// ── Picker thumbnails ─────────────────────────────────────────────────────────
const cards = document.querySelectorAll('.picker-card');

const io = new IntersectionObserver(entries => {
    for (const e of entries) {
        if (!e.isIntersecting) continue;
        io.unobserve(e.target);
        const card = e.target;
        const v = new ModelViewer(card.querySelector('canvas'), { background: 0x16213e });
        v.loadModel(`/api/file?bucket=models&key=${encodeURIComponent(card.dataset.key)}`,
                    card.dataset.fmt).catch(() => {});
    }
}, { threshold: 0.1 });
cards.forEach(c => io.observe(c));

// ── Model selection ───────────────────────────────────────────────────────────
cards.forEach(card => {
    card.addEventListener('click', () => {
        cards.forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');
        selectedModel = { id: card.dataset.id, key: card.dataset.key, fmt: card.dataset.fmt };
        document.getElementById('h-model-id').value  = selectedModel.id;
        document.getElementById('hint-model').style.color   = '#4ade80';
        document.getElementById('hint-model').textContent   = '✓ Modell gewählt';
        document.getElementById('submit-btn').disabled = false;
        showEditor();
    });
});

// ── Editor init ───────────────────────────────────────────────────────────────
function showEditor() {
    const section = document.getElementById('editor-section');
    section.classList.add('visible');
    const url = `/api/file?bucket=models&key=${encodeURIComponent(selectedModel.key)}`;

    if (!editorReady) {
        editorReady = true;
        requestAnimationFrame(() => requestAnimationFrame(() => {
            const canvas = document.getElementById('mag-canvas');
            editor = new MagnetEditor(canvas);
            editor.forceResize();

            editor.onSelect = (nTris) => {
                const s = document.getElementById('face-status');
                s.className = 'face-ok';
                s.textContent = `✓ Fläche gewählt (${nTris} Dreiecke)`;
                document.getElementById('hint-face').style.color   = '#4ade80';
                document.getElementById('hint-face').textContent   = '✓ Fläche gewählt';
                syncFaceHidden();
            };
            editor.onClear = () => {
                const s = document.getElementById('face-status');
                s.className = 'face-none';
                s.textContent = 'Noch keine Fläche gewählt.';
                document.getElementById('hint-face').style.color   = '#94a3b8';
                document.getElementById('hint-face').textContent   = '– Fläche (optional)';
                document.getElementById('h-face').value = '';
            };

            // Apply current control values to editor
            editor.setDiameter(parseFloat(document.getElementById('inp-diam').value));
            editor.setNMagnets(parseInt(document.getElementById('n-val').textContent));

            editor.loadModel(url, selectedModel.fmt).catch(console.error);
        }));
    } else {
        editor.forceResize();
        editor.loadModel(url, selectedModel.fmt).catch(console.error);
    }
}

function syncFaceHidden() {
    if (!editor) return;
    const sel = editor.getSelection();
    document.getElementById('h-face').value = sel ? JSON.stringify(sel) : '';
}

// ── Anzahl Löcher stepper ─────────────────────────────────────────────────────
let nMagnets = 4;

document.getElementById('n-minus').addEventListener('click', () => {
    if (nMagnets <= 1) return;
    nMagnets--;
    document.getElementById('n-val').textContent = nMagnets;
    document.getElementById('h-n').value = nMagnets;
    if (editor) editor.setNMagnets(nMagnets);
});
document.getElementById('n-plus').addEventListener('click', () => {
    if (nMagnets >= 8) return;
    nMagnets++;
    document.getElementById('n-val').textContent = nMagnets;
    document.getElementById('h-n').value = nMagnets;
    if (editor) editor.setNMagnets(nMagnets);
});

// ── Pattern selector ──────────────────────────────────────────────────────────
document.querySelectorAll('#pattern-btns .pat-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('#pattern-btns .pat-btn').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        if (editor) editor.setPattern(btn.dataset.pattern);
    });
});

// ── Numeric inputs ────────────────────────────────────────────────────────────
document.getElementById('inp-diam').addEventListener('input', function () {
    document.getElementById('h-diam').value = this.value;
    if (editor) editor.setDiameter(parseFloat(this.value));
});
document.getElementById('inp-len').addEventListener('input', function () {
    document.getElementById('h-len').value = this.value;
});

// ── Submit ────────────────────────────────────────────────────────────────────
document.getElementById('mag-form').addEventListener('submit', () => {
    // Snapshot all current values before submit
    document.getElementById('h-diam').value = document.getElementById('inp-diam').value;
    document.getElementById('h-len').value  = document.getElementById('inp-len').value;
    document.getElementById('h-n').value    = nMagnets;
    syncFaceHidden();
});
</script>

<?php $content = ob_get_clean();
require __DIR__ . '/../layouts/base.php'; ?>
