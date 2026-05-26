<?php ob_start(); ?>

<script src="https://unpkg.com/jszip@3.10.1/dist/jszip.min.js"></script>
<?php
$vv = filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/viewer.js');
$ve = filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/logo-editor.js');
?>
<script type="importmap">
{
  "imports": {
    "three":            "https://unpkg.com/three@0.167.0/build/three.module.js",
    "three/addons/":    "https://unpkg.com/three@0.167.0/examples/jsm/",
    "@app/viewer":      "/assets/js/viewer.js?v=<?= $vv ?>",
    "@app/logo-editor": "/assets/js/logo-editor.js?v=<?= $ve ?>"
  }
}
</script>

<style>
.job-layout  { display:grid; grid-template-columns:1fr 320px; gap:1.5rem; align-items:start; }
@media(max-width:800px){ .job-layout { grid-template-columns:1fr; } }

/* Model picker */
.picker-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:.75rem; margin-top:.75rem; }
.picker-card { border:2px solid #2d3748; border-radius:8px; overflow:hidden; cursor:pointer;
               transition:border-color .2s, box-shadow .2s; background:#16213e; }
.picker-card:hover    { border-color:#60a5fa; }
.picker-card.selected { border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.3); }
.picker-card canvas   { width:100%; height:90px; display:block; pointer-events:none; }
.picker-card .label   { padding:.4rem .6rem; font-size:.78rem; font-weight:600;
                        white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* Editor section */
#editor-section { display:none; margin-top:1.5rem; }
#editor-section.visible { display:block; }

.editor-wrap { display:grid; grid-template-columns:1fr 280px; gap:1.2rem; align-items:start; }
@media(max-width:800px){ .editor-wrap { grid-template-columns:1fr; } }

#editor-canvas { width:100%; height:480px; display:block; border-radius:8px;
                 border:1px solid #2d3748; background:#111827; }

.controls-panel { background:#1e2433; border:1px solid #2d3748; border-radius:8px; padding:1.1rem;
                  display:flex; flex-direction:column; gap:.85rem; }
.ctrl-group { display:flex; flex-direction:column; gap:.4rem; }
.ctrl-label { font-size:.8rem; font-weight:700; color:#94a3b8; display:flex; justify-content:space-between; }
.hint-box   { background:#1e3a5f; border:1px solid #1d4ed8; border-radius:6px; padding:.65rem .85rem;
              font-size:.8rem; color:#93c5fd; line-height:1.5; }
.sep        { border:none; border-top:1px solid #2d3748; margin:0; }

/* Layer list */
.layer-add-row { display:grid; grid-template-columns:1fr 1fr; gap:.4rem; }
.layer-list    { display:flex; flex-direction:column; gap:.3rem; min-height:36px;
                 max-height:160px; overflow-y:auto; }
.layer-item    { display:flex; align-items:center; gap:.5rem; padding:.38rem .6rem;
                 border-radius:6px; border:1px solid #374151; cursor:pointer;
                 transition:border-color .15s; user-select:none; }
.layer-item:hover  { border-color:#60a5fa; }
.layer-item.active { border-color:#3b82f6; background:rgba(59,130,246,.1); }
.layer-badge       { font-size:.68rem; font-weight:700; padding:.1rem .35rem;
                     border-radius:3px; flex-shrink:0; }
.layer-badge.logo  { background:#3b82f6; color:#fff; }
.layer-badge.text  { background:#8b5cf6; color:#fff; }
.layer-name        { flex:1; font-size:.8rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.layer-del         { font-size:.8rem; color:#6b7280; border:none; background:none;
                     cursor:pointer; padding:0 .25rem; line-height:1; flex-shrink:0; }
.layer-del:hover   { color:#ef4444; }

/* Active layer controls */
#active-controls { display:none; flex-direction:column; gap:.75rem; }

/* Right sidebar */
.sidebar { display:flex; flex-direction:column; gap:1rem; }
</style>

<?php
// $preselected, $prelogoKey, $templateData are set by JobController::create()
$preselected  = $preselected  ?? ($_GET['model_id'] ?? '');
$prelogoKey   = $prelogoKey   ?? ($_GET['logo_key'] ?? '');
$templateData = $templateData ?? null;
?>

<h2 style="margin-bottom:1.2rem;font-size:1.3rem;">Neuer Job</h2>

<div class="job-layout">
    <!-- Left: model picker + editor -->
    <div>
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <h3 style="font-size:1rem;">1. 3D-Modell wählen</h3>
                <a href="/models/new" class="btn btn-ghost" style="font-size:.8rem;">+ Hochladen</a>
            </div>

            <?php if (empty($models)): ?>
                <p style="color:#4b5563;margin-top:.75rem;">
                    Noch keine Modelle in der <a href="/models" style="color:#60a5fa;">Bibliothek</a>.
                </p>
            <?php else: ?>
            <div class="picker-grid" id="picker-grid">
                <?php foreach ($models as $m): ?>
                <div class="picker-card <?= $m['id'] === $preselected ? 'selected' : '' ?>"
                     data-id="<?= htmlspecialchars($m['id']) ?>"
                     data-key="<?= htmlspecialchars($m['storage_key']) ?>"
                     data-fmt="<?= htmlspecialchars($m['format']) ?>"
                     data-plate="<?= htmlspecialchars($m['plate_index'] ?? '') ?>"
                     data-filter="<?= htmlspecialchars($m['filter_objects'] ?? '') ?>"
                     title="<?= htmlspecialchars($m['filename']) ?>">
                    <canvas></canvas>
                    <div class="label"><?= htmlspecialchars($m['filename']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Step 2: Layer Editor (shown after model selected) -->
        <div id="editor-section">
            <div class="card" style="padding:1rem 1rem .75rem;">
                <h3 style="font-size:1rem;margin-bottom:.75rem;">2. Ebenen auf Modell platzieren</h3>
                <div class="editor-wrap">
                    <canvas id="editor-canvas"></canvas>

                    <div class="controls-panel">
                        <div class="hint-box">
                            Füge Ebenen hinzu, lade Logo/Text, dann <strong>klicke auf die 3D-Oberfläche</strong>. Orbit mit Drag.
                        </div>

                        <!-- Add layer buttons -->
                        <div class="ctrl-group">
                            <span class="ctrl-label">Ebenen</span>
                            <div class="layer-add-row">
                                <button type="button" class="btn btn-ghost" id="btn-add-logo"
                                        style="font-size:.8rem;padding:.35rem .5rem;">+ Logo</button>
                                <button type="button" class="btn btn-ghost" id="btn-add-text"
                                        style="font-size:.8rem;padding:.35rem .5rem;">+ Text</button>
                            </div>
                            <div class="layer-list" id="layer-list">
                                <div id="layer-empty" style="font-size:.76rem;color:#4b5563;text-align:center;padding:.4rem;">
                                    Noch keine Ebenen
                                </div>
                            </div>
                        </div>

                        <hr class="sep">

                        <!-- Active-layer controls (hidden when no layer selected) -->
                        <div id="active-controls">

                            <!-- Logo input (shown for logo layers) -->
                            <div id="active-logo-panel" class="ctrl-group">
                                <span class="ctrl-label">Logo (PNG/JPG)</span>
                                <input type="file" id="logo-picker" accept="image/png,image/jpeg,image/webp">
                            </div>

                            <!-- Text input (shown for text layers) -->
                            <div id="active-text-panel" style="display:none;flex-direction:column;gap:.55rem;">
                                <div class="ctrl-group">
                                    <span class="ctrl-label">Text</span>
                                    <textarea id="text-input" rows="2" placeholder="Text eingeben…"
                                        style="width:100%;background:#111827;border:1px solid #374151;
                                               border-radius:6px;color:#e2e8f0;padding:.45rem .7rem;
                                               font-size:.88rem;resize:vertical;box-sizing:border-box;"></textarea>
                                </div>
                                <div class="ctrl-group">
                                    <span class="ctrl-label">Schriftart</span>
                                    <select id="text-font"
                                        style="width:100%;background:#111827;border:1px solid #374151;
                                               border-radius:6px;color:#e2e8f0;padding:.4rem .7rem;font-size:.85rem;">
                                        <option value="Arial">Arial</option>
                                        <option value="'Times New Roman'">Times New Roman</option>
                                        <option value="'Courier New'">Courier New</option>
                                        <option value="Georgia">Georgia</option>
                                        <option value="Verdana">Verdana</option>
                                        <option value="Impact">Impact</option>
                                    </select>
                                </div>
                                <div style="display:grid;grid-template-columns:1fr auto;gap:.5rem;align-items:end;">
                                    <div class="ctrl-group">
                                        <span class="ctrl-label">Größe <span id="text-size-val">60</span>px</span>
                                        <input type="range" id="text-size" min="12" max="200" step="2" value="60">
                                    </div>
                                    <div class="ctrl-group" style="margin-bottom:.1rem;">
                                        <span class="ctrl-label">Stil</span>
                                        <div style="display:flex;gap:.4rem;align-items:center;margin-top:.25rem;">
                                            <label style="display:flex;align-items:center;gap:.25rem;cursor:pointer;font-size:.85rem;">
                                                <input type="checkbox" id="text-bold"> <strong>F</strong>
                                            </label>
                                            <label style="display:flex;align-items:center;gap:.25rem;cursor:pointer;font-size:.85rem;">
                                                <input type="checkbox" id="text-italic"> <em>K</em>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="ctrl-group">
                                    <span class="ctrl-label">Vorschau</span>
                                    <canvas id="text-preview"
                                        style="width:100%;height:48px;border:1px solid #374151;
                                               border-radius:4px;background:#111827;display:block;"></canvas>
                                </div>
                                <button type="button" class="btn btn-ghost" id="btn-apply-text"
                                        style="font-size:.8rem;padding:.35rem;">Text übernehmen</button>
                            </div>

                            <!-- Shared per-layer controls -->
                            <div class="ctrl-group">
                                <span class="ctrl-label">Druckfarbe</span>
                                <input type="color" id="layer-color" value="#ffffff"
                                       style="width:100%;height:2rem;border:none;border-radius:5px;cursor:pointer;background:none;">
                            </div>
                            <div class="ctrl-group">
                                <span class="ctrl-label">Größe <span id="size-val">0.30</span></span>
                                <input type="range" id="size-slider" min="0.05" max="2" step="0.01" value="0.3">
                            </div>
                            <div class="ctrl-group">
                                <span class="ctrl-label">Rotation <span id="rot-val">0°</span></span>
                                <input type="range" id="rot-slider" min="0" max="360" step="1" value="0">
                            </div>

                            <div id="placement-status" style="font-size:.78rem;color:#4b5563;">
                                Noch kein Platzierungspunkt gewählt.
                            </div>
                        </div>

                        <hr class="sep">

                        <!-- Global model color -->
                        <div class="ctrl-group">
                            <span class="ctrl-label">Modell-Farbe</span>
                            <input type="color" id="model-color" value="#4a9eff"
                                   style="width:100%;height:2rem;border:none;border-radius:5px;cursor:pointer;background:none;">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right sidebar -->
    <div class="sidebar">
        <div class="card">
            <h3 style="font-size:1rem;margin-bottom:.75rem;">3. Job starten</h3>
            <form id="job-form" method="POST" action="/jobs/new" enctype="multipart/form-data"
                  style="display:flex;flex-direction:column;gap:.9rem;">
                <input type="hidden" name="model_id"      id="f-model-id">
                <input type="hidden" name="model_color"   id="f-model-color" value="#4a9eff">
                <input type="hidden" name="layers_config" id="f-layers-config">

                <div id="submit-hints" style="font-size:.82rem;color:#64748b;line-height:1.6;">
                    <div id="hint-model"  style="color:#f87171;">✗ Modell wählen</div>
                    <div id="hint-layers" style="color:#f87171;">✗ Mindestens eine Ebene mit Logo/Text</div>
                    <div id="hint-place"  style="color:#94a3b8;">– Platzierung (optional)</div>
                </div>

                <button type="submit" id="submit-btn" class="btn btn-primary" disabled>Job starten</button>
                <button type="button" id="btn-save-template" class="btn btn-ghost"
                        style="width:100%;justify-content:center;" disabled>
                    Als Vorlage speichern
                </button>
            </form>
        </div>

        <!-- Save-as-template modal -->
        <div id="tmpl-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);
             z-index:1000;align-items:center;justify-content:center;">
            <div style="background:#1e2433;border:1px solid #374151;border-radius:10px;
                        padding:1.5rem;width:320px;display:flex;flex-direction:column;gap:1rem;">
                <h3 style="font-size:1rem;">Vorlage speichern</h3>
                <form id="tmpl-form" method="POST" action="/templates/new" enctype="multipart/form-data"
                      style="display:flex;flex-direction:column;gap:.75rem;">
                    <input type="hidden" name="model_id"        id="tf-model-id">
                    <input type="hidden" name="model_color"     id="tf-model-color">
                    <input type="hidden" name="template_layers" id="tf-layers">
                    <input type="hidden" name="support_mode"    id="tf-support-mode">
                    <input type="hidden" name="support_angle"   id="tf-support-angle">
                    <input type="hidden" name="brim_width"      id="tf-brim-width">
                    <input type="hidden" name="layer_height"    id="tf-layer-height">
                    <div>
                        <label style="display:block;font-size:.8rem;color:#94a3b8;margin-bottom:.3rem;">Vorlagenname</label>
                        <input type="text" name="template_name" id="tf-name" required
                               placeholder="z.B. Firmenschild Vorlage"
                               style="width:100%;background:#111827;border:1px solid #374151;
                                      border-radius:6px;color:#e2e8f0;padding:.45rem .7rem;font-size:.88rem;">
                    </div>
                    <div style="display:flex;gap:.5rem;">
                        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;">Speichern</button>
                        <button type="button" id="btn-tmpl-cancel" class="btn btn-ghost">Abbrechen</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Print settings card -->
        <div class="card">
            <h3 style="font-size:1rem;margin-bottom:.75rem;">Druckeinstellungen</h3>
            <div style="display:flex;flex-direction:column;gap:.75rem;">

                <div class="ctrl-group" style="gap:.35rem;">
                    <span class="ctrl-label" style="color:#94a3b8;font-size:.8rem;font-weight:700;">Druckrichtung</span>
                    <select id="f-print-direction" form="job-form" name="print_direction"
                            style="width:100%;background:#111827;border:1px solid #374151;
                                   border-radius:6px;color:#e2e8f0;padding:.4rem .7rem;font-size:.85rem;">
                        <option value="none">Standard (Z+ oben)</option>
                        <option value="flip_z">Umdrehen (Z- oben)</option>
                        <option value="x_pos">Auf rechter Seite (+X unten)</option>
                        <option value="x_neg">Auf linker Seite (-X unten)</option>
                        <option value="y_pos">Auf Vorderseite (+Y unten)</option>
                        <option value="y_neg">Auf Rückseite (-Y unten)</option>
                    </select>
                </div>

                <div class="ctrl-group" style="gap:.35rem;">
                    <span class="ctrl-label" style="color:#94a3b8;font-size:.8rem;font-weight:700;">Stützen</span>
                    <select name="support_mode" id="f-support-mode" form="job-form"
                            style="width:100%;background:#111827;border:1px solid #374151;
                                   border-radius:6px;color:#e2e8f0;padding:.4rem .7rem;font-size:.85rem;">
                        <option value="none">Keine</option>
                        <option value="auto" selected>Automatisch</option>
                        <option value="everywhere">Überall</option>
                    </select>
                </div>

                <div class="ctrl-group" id="support-angle-group" style="gap:.35rem;">
                    <span style="font-size:.8rem;font-weight:700;color:#94a3b8;display:flex;justify-content:space-between;">
                        Stützenwinkel <span id="angle-val">45</span>°
                    </span>
                    <input type="range" name="support_angle" id="f-support-angle" form="job-form"
                           min="20" max="70" step="5" value="45">
                </div>

                <div class="ctrl-group" style="gap:.35rem;">
                    <span class="ctrl-label" style="color:#94a3b8;font-size:.8rem;font-weight:700;">Brim-Breite</span>
                    <select name="brim_width" id="f-brim-width" form="job-form"
                            style="width:100%;background:#111827;border:1px solid #374151;
                                   border-radius:6px;color:#e2e8f0;padding:.4rem .7rem;font-size:.85rem;">
                        <option value="0">Kein Brim</option>
                        <option value="3">3 mm</option>
                        <option value="5" selected>5 mm</option>
                        <option value="8">8 mm</option>
                    </select>
                </div>

                <div class="ctrl-group" style="gap:.35rem;">
                    <span class="ctrl-label" style="color:#94a3b8;font-size:.8rem;font-weight:700;">Schichthöhe</span>
                    <select name="layer_height" id="f-layer-height" form="job-form"
                            style="width:100%;background:#111827;border:1px solid #374151;
                                   border-radius:6px;color:#e2e8f0;padding:.4rem .7rem;font-size:.85rem;">
                        <option value="0.10">0.10 mm (Fein)</option>
                        <option value="0.20" selected>0.20 mm (Standard)</option>
                        <option value="0.30">0.30 mm (Schnell)</option>
                    </select>
                </div>

                <label style="display:flex;align-items:center;gap:.55rem;cursor:pointer;padding:.2rem 0;">
                    <input type="checkbox" name="detail_fix" value="1" form="job-form"
                           style="width:15px;height:15px;accent-color:#3b82f6;cursor:pointer;">
                    <span style="font-size:.82rem;color:#cbd5e1;line-height:1.4;">
                        Feine Details optimieren<br>
                        <span style="color:#64748b;font-size:.75rem;">Schließt zu schmale Strukturen (&lt; 0,4 mm)</span>
                    </span>
                </label>

            </div>
        </div>

        <div class="card" style="font-size:.82rem;color:#64748b;line-height:1.7;">
            <strong style="color:#94a3b8;">Format-Unterstützung</strong><br>
            Modell: STL, OBJ, 3MF<br>
            Logo: PNG / JPEG / WebP<br>
            Text: beliebige Schrift, direkt eingeben<br>
            <br>
            Mehrere Logos und Texte kombinierbar.
        </div>
    </div>
</div>

<script type="module">
import { ModelViewer } from '@app/viewer';
import { LogoEditor }  from '@app/logo-editor';

// ── Global state ──────────────────────────────────────────────────────────────
let selectedModel = null;
let editorReady   = false;
let editor        = null;

// layers: Map<id, { id, type, name, blobFile, existingKey, color, size, rotation,
//                   hasFile, placed, textContent, fontSize, fontFamily, textBold, textItalic, blobUrl }>
const layers = new Map();
let activeLayerId = null;
let layerCounter  = 0;

// ── Layer management ──────────────────────────────────────────────────────────

function addLayer(type) {
    layerCounter++;
    const id   = `layer-${layerCounter}`;
    const name = type === 'logo' ? `Logo ${layerCounter}` : `Text ${layerCounter}`;
    layers.set(id, {
        id, type, name,
        blobFile: null, existingKey: null, blobUrl: null,
        color: '#ffffff', size: 0.3, rotation: 0,
        hasFile: false, placed: false,
        // text-specific
        textContent: '', fontSize: 60, fontFamily: 'Arial', textBold: false, textItalic: false,
    });

    if (editor) editor.addLayer(id, '#ffffff');

    activateLayer(id);
}

function removeLayer(id) {
    const layer = layers.get(id);
    if (!layer) return;
    if (layer.blobUrl) URL.revokeObjectURL(layer.blobUrl);
    layers.delete(id);
    if (editor) editor.removeLayer(id);

    if (activeLayerId === id) {
        const keys = [...layers.keys()];
        activeLayerId = keys.length > 0 ? keys[keys.length - 1] : null;
        if (activeLayerId && editor) editor.activateLayer(activeLayerId);
    }

    renderLayerList();
    renderActiveControls();
    updateHints();
}

function activateLayer(id) {
    saveActiveLayerControls();  // save current UI state into previous layer

    activeLayerId = id;
    if (editor) editor.activateLayer(id);

    renderLayerList();
    renderActiveControls();
}

// ── Render layer list ─────────────────────────────────────────────────────────

function renderLayerList() {
    const list  = document.getElementById('layer-list');
    const empty = document.getElementById('layer-empty');

    // Remove all layer items (keep the empty placeholder)
    list.querySelectorAll('.layer-item').forEach(el => el.remove());

    if (layers.size === 0) {
        empty.style.display = '';
        return;
    }
    empty.style.display = 'none';

    for (const [id, layer] of layers) {
        const item = document.createElement('div');
        item.className = 'layer-item' + (id === activeLayerId ? ' active' : '');

        const statusDot = layer.placed ? ' ✓' : (layer.hasFile ? ' ●' : '');
        item.innerHTML = `
            <span class="layer-badge ${layer.type}">${layer.type === 'logo' ? 'L' : 'T'}</span>
            <span class="layer-name">${layer.name}${statusDot}</span>
            <button class="layer-del" title="Ebene entfernen">✕</button>
        `;
        item.querySelector('.layer-del').addEventListener('click', e => {
            e.stopPropagation();
            removeLayer(id);
        });
        item.addEventListener('click', () => activateLayer(id));
        list.appendChild(item);
    }
}

// ── Render active-layer controls ──────────────────────────────────────────────

function renderActiveControls() {
    const ctrl  = document.getElementById('active-controls');
    const layer = layers.get(activeLayerId);

    if (!layer) {
        ctrl.style.display = 'none';
        document.getElementById('placement-status').textContent = 'Noch kein Platzierungspunkt gewählt.';
        return;
    }

    ctrl.style.display = 'flex';

    const isText = layer.type === 'text';
    document.getElementById('active-logo-panel').style.display        = isText ? 'none'  : '';
    document.getElementById('active-text-panel').style.display        = isText ? 'flex'  : 'none';
    document.getElementById('active-text-panel').style.flexDirection  = 'column';

    // Restore per-layer values into shared controls
    document.getElementById('layer-color').value           = layer.color;
    document.getElementById('size-slider').value           = layer.size;
    document.getElementById('rot-slider').value            = Math.round(layer.rotation * 180 / Math.PI);
    document.getElementById('size-val').textContent        = layer.size.toFixed(2);
    document.getElementById('rot-val').textContent         = Math.round(layer.rotation * 180 / Math.PI) + '°';

    // Restore text-specific values
    if (isText) {
        document.getElementById('text-input').value        = layer.textContent;
        document.getElementById('text-font').value         = layer.fontFamily;
        document.getElementById('text-size').value         = layer.fontSize;
        document.getElementById('text-size-val').textContent = layer.fontSize;
        document.getElementById('text-bold').checked       = layer.textBold;
        document.getElementById('text-italic').checked     = layer.textItalic;
        if (layer.textContent) drawTextPreview(renderTextCanvas(layer));
        else {
            const pv = document.getElementById('text-preview');
            const ctx = pv.getContext('2d');
            ctx.clearRect(0, 0, pv.width, pv.height);
        }
    }

    // Placement status
    document.getElementById('placement-status').textContent =
        layer.placed ? '✓ Platziert' : 'Klicke auf die 3D-Oberfläche um zu platzieren.';
}

function saveActiveLayerControls() {
    const layer = layers.get(activeLayerId);
    if (!layer) return;
    // size and rotation are already updated live in event handlers
    // text controls need saving if switching from text layer
    if (layer.type === 'text') {
        layer.textContent  = document.getElementById('text-input').value;
        layer.fontFamily   = document.getElementById('text-font').value;
        layer.fontSize     = parseInt(document.getElementById('text-size').value);
        layer.textBold     = document.getElementById('text-bold').checked;
        layer.textItalic   = document.getElementById('text-italic').checked;
    }
}

// ── Picker thumbnails ─────────────────────────────────────────────────────────

const cards = document.querySelectorAll('.picker-card');

const io = new IntersectionObserver((entries) => {
    for (const e of entries) {
        if (!e.isIntersecting) continue;
        io.unobserve(e.target);
        const card          = e.target;
        const canvas        = card.querySelector('canvas');
        const v             = new ModelViewer(canvas, { background: 0x16213e });
        const filterObjects = card.dataset.filter ? JSON.parse(card.dataset.filter) : null;
        const plateIndex    = card.dataset.plate  ? parseInt(card.dataset.plate)    : null;
        const thumbOpts     = filterObjects ? { filterObjectIds: filterObjects }
                            : plateIndex    ? { plateIndex }
                            : {};
        v.loadModel(`/api/file?bucket=models&key=${encodeURIComponent(card.dataset.key)}`,
            card.dataset.fmt, thumbOpts).catch(() => {});
    }
}, { threshold: 0.1 });

cards.forEach(c => io.observe(c));

// ── Model selection ───────────────────────────────────────────────────────────

cards.forEach(card => {
    card.addEventListener('click', () => {
        cards.forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');

        selectedModel = {
            id:            card.dataset.id,
            key:           card.dataset.key,
            fmt:           card.dataset.fmt,
            plateIndex:    card.dataset.plate  ? parseInt(card.dataset.plate)    : null,
            filterObjects: card.dataset.filter ? JSON.parse(card.dataset.filter) : null,
        };
        document.getElementById('f-model-id').value = selectedModel.id;
        document.getElementById('hint-model').style.color   = '#4ade80';
        document.getElementById('hint-model').textContent   = '✓ Modell gewählt';
        updateHints();

        showEditor();
    });
});

// Pre-select from query param
const preId = <?= json_encode($preselected) ?>;
if (preId) {
    const pre = document.querySelector(`.picker-card[data-id="${preId}"]`);
    if (pre) pre.click();
}

// ── Editor init ───────────────────────────────────────────────────────────────

function showEditor() {
    const section = document.getElementById('editor-section');
    section.classList.add('visible');

    const url = `/api/file?bucket=models&key=${encodeURIComponent(selectedModel.key)}`;

    if (!editorReady) {
        editorReady = true;
        requestAnimationFrame(() => requestAnimationFrame(() => {
            const canvas = document.getElementById('editor-canvas');
            editor = new LogoEditor(canvas);
            editor.forceResize();

            // Re-register all existing layers with the fresh editor
            for (const [id, layer] of layers) {
                editor.addLayer(id, layer.color);
                if (layer.blobUrl) editor.setLayerLogo(id, layer.blobUrl);
                editor.setLayerSize(id, layer.size);
                editor.setLayerRotation(id, layer.rotation);
            }
            if (activeLayerId) editor.activateLayer(activeLayerId);

            editor.onLayerPlaced = onLayerPlaced;

            const opts3mf = selectedModel.filterObjects ? { filterObjectIds: selectedModel.filterObjects }
                          : selectedModel.plateIndex    ? { plateIndex: selectedModel.plateIndex }
                          : {};
            editor.loadModel(url, selectedModel.fmt, opts3mf)
                  .then(() => loadTemplateData())
                  .catch(console.error);
            editor.setModelColor(document.getElementById('model-color').value);
        }));
    } else {
        editor.forceResize();
        const opts3mf = selectedModel.filterObjects ? { filterObjectIds: selectedModel.filterObjects }
                      : selectedModel.plateIndex    ? { plateIndex: selectedModel.plateIndex }
                      : {};
        editor.loadModel(url, selectedModel.fmt, opts3mf).catch(console.error);
    }
}

function onLayerPlaced(id) {
    const layer = layers.get(id);
    if (layer) {
        layer.placed = true;
        renderLayerList();
        document.getElementById('placement-status').textContent = '✓ Platziert';
        updateHints();
    }
}

// ── Add layer buttons ─────────────────────────────────────────────────────────

document.getElementById('btn-add-logo').addEventListener('click', () => addLayer('logo'));
document.getElementById('btn-add-text').addEventListener('click', () => addLayer('text'));

// ── Logo picker for active layer ──────────────────────────────────────────────

document.getElementById('logo-picker').addEventListener('change', function () {
    const file  = this.files[0];
    const layer = layers.get(activeLayerId);
    if (!file || !layer) return;

    if (layer.blobUrl) URL.revokeObjectURL(layer.blobUrl);
    layer.blobFile  = file;
    layer.blobUrl   = URL.createObjectURL(file);
    layer.hasFile   = true;

    if (editor) editor.setLayerLogo(activeLayerId, layer.blobUrl);
    renderLayerList();
    updateHints();
});

// ── Text rendering ────────────────────────────────────────────────────────────

function renderTextCanvas(layer) {
    const { textContent: text, fontSize, fontFamily, textBold, textItalic, color } = layer;
    if (!text?.trim()) return null;
    const weight  = textBold   ? 'bold'   : 'normal';
    const style   = textItalic ? 'italic' : 'normal';
    const font    = `${style} ${weight} ${fontSize}px ${fontFamily}`;
    const pad     = Math.ceil(fontSize * 0.3);
    const lineH   = Math.ceil(fontSize * 1.35);
    const lines   = text.split('\n').filter(l => l.length > 0);
    if (!lines.length) return null;

    const tmp  = document.createElement('canvas');
    const tctx = tmp.getContext('2d');
    tctx.font  = font;
    const maxW = Math.max(...lines.map(l => tctx.measureText(l).width));

    const c   = document.createElement('canvas');
    c.width   = Math.ceil(maxW) + pad * 2;
    c.height  = lineH * lines.length + pad * 2;
    const ctx = c.getContext('2d');
    ctx.font         = font;
    ctx.fillStyle    = '#ffffff';
    ctx.textBaseline = 'top';
    lines.forEach((line, i) => ctx.fillText(line, pad, pad + i * lineH));
    return c;
}

function drawTextPreview(srcCanvas) {
    const preview = document.getElementById('text-preview');
    const dpr  = window.devicePixelRatio || 1;
    const pw   = (preview.clientWidth  || 200) * dpr;
    const ph   = (preview.clientHeight || 48)  * dpr;
    preview.width  = pw;
    preview.height = ph;
    const ctx  = preview.getContext('2d');
    ctx.clearRect(0, 0, pw, ph);
    if (!srcCanvas) return;
    const layer = layers.get(activeLayerId);
    const scale = Math.min(pw / srcCanvas.width, ph / srcCanvas.height) * 0.9;
    const ox = (pw - srcCanvas.width  * scale) / 2;
    const oy = (ph - srcCanvas.height * scale) / 2;
    ctx.fillStyle = layer?.color || '#ffffff';
    ctx.fillRect(0, 0, pw, ph);
    ctx.globalCompositeOperation = 'destination-in';
    ctx.drawImage(srcCanvas, ox, oy, srcCanvas.width * scale, srcCanvas.height * scale);
    ctx.globalCompositeOperation = 'source-over';
}

function applyTextToLayer() {
    const layer = layers.get(activeLayerId);
    if (!layer || layer.type !== 'text') return;

    // Save current UI values into layer state
    layer.textContent  = document.getElementById('text-input').value;
    layer.fontFamily   = document.getElementById('text-font').value;
    layer.fontSize     = parseInt(document.getElementById('text-size').value);
    layer.textBold     = document.getElementById('text-bold').checked;
    layer.textItalic   = document.getElementById('text-italic').checked;

    const srcCanvas = renderTextCanvas(layer);
    drawTextPreview(srcCanvas);
    if (!srcCanvas) return;

    srcCanvas.toBlob(blob => {
        if (layer.blobUrl) URL.revokeObjectURL(layer.blobUrl);
        layer.blobUrl  = URL.createObjectURL(blob);
        layer.blobFile = new File([blob], 'text.png', { type: 'image/png' });
        layer.hasFile  = true;

        if (editor) editor.setLayerLogo(activeLayerId, layer.blobUrl);
        renderLayerList();
        updateHints();
    }, 'image/png');
}

document.getElementById('btn-apply-text').addEventListener('click', applyTextToLayer);

// Auto-apply on text control change
['text-input', 'text-font'].forEach(id =>
    document.getElementById(id).addEventListener('input', applyTextToLayer)
);
['text-bold', 'text-italic'].forEach(id =>
    document.getElementById(id).addEventListener('change', applyTextToLayer)
);
document.getElementById('text-size').addEventListener('input', function () {
    document.getElementById('text-size-val').textContent = this.value;
    const layer = layers.get(activeLayerId);
    if (layer) layer.fontSize = parseInt(this.value);
    applyTextToLayer();
});

// ── Per-layer colour ──────────────────────────────────────────────────────────

document.getElementById('layer-color').addEventListener('input', function () {
    const layer = layers.get(activeLayerId);
    if (!layer) return;
    layer.color = this.value;
    if (editor) editor.setLayerColor(activeLayerId, this.value);
    if (layer.type === 'text' && layer.textContent) drawTextPreview(renderTextCanvas(layer));
});

// ── Sliders (size / rotation) ─────────────────────────────────────────────────

document.getElementById('size-slider').addEventListener('input', function () {
    const v = parseFloat(this.value);
    document.getElementById('size-val').textContent = v.toFixed(2);
    const layer = layers.get(activeLayerId);
    if (layer) layer.size = v;
    if (editor && activeLayerId) editor.setLayerSize(activeLayerId, v);
});

document.getElementById('rot-slider').addEventListener('input', function () {
    const deg = parseFloat(this.value);
    document.getElementById('rot-val').textContent = Math.round(deg) + '°';
    const rad = deg * Math.PI / 180;
    const layer = layers.get(activeLayerId);
    if (layer) layer.rotation = rad;
    if (editor && activeLayerId) editor.setLayerRotation(activeLayerId, rad);
});

// ── Model colour ──────────────────────────────────────────────────────────────

document.getElementById('model-color').addEventListener('input', function () {
    document.getElementById('f-model-color').value = this.value;
    if (editor) editor.setModelColor(this.value);
});

// ── Hints + submit ────────────────────────────────────────────────────────────

function updateHints() {
    const hasLayers = [...layers.values()].some(l => l.hasFile);
    const placed    = [...layers.values()].filter(l => l.placed).length;

    document.getElementById('hint-layers').style.color =
        hasLayers ? '#4ade80' : '#f87171';
    document.getElementById('hint-layers').textContent =
        hasLayers ? `✓ ${[...layers.values()].filter(l => l.hasFile).length} Ebene(n) bereit` : '✗ Mindestens eine Ebene mit Logo/Text';

    document.getElementById('hint-place').style.color =
        placed > 0 ? '#4ade80' : '#94a3b8';
    document.getElementById('hint-place').textContent =
        placed > 0 ? `✓ ${placed} Ebene(n) platziert` : '– Platzierung (optional)';

    document.getElementById('submit-btn').disabled        = !(selectedModel && hasLayers);
    document.getElementById('btn-save-template').disabled = !(selectedModel && hasLayers);
}

// ── Form submission ───────────────────────────────────────────────────────────

document.getElementById('job-form').addEventListener('submit', function (e) {
    const config   = [];
    let   fileIdx  = 0;

    for (const [id, layer] of layers) {
        if (!layer.hasFile) continue;

        const placement = editor?.getLayerPlacement(id) ?? null;
        const entry     = { placement, color: layer.color };

        if (layer.blobFile) {
            entry.file_index = fileIdx;

            // Create a hidden file input and attach blob
            const inp = document.createElement('input');
            inp.type  = 'file';
            inp.name  = `layer_file_${fileIdx}`;
            inp.style.display = 'none';
            const dt  = new DataTransfer();
            dt.items.add(layer.blobFile);
            inp.files = dt.files;
            this.appendChild(inp);
            fileIdx++;
        } else if (layer.existingKey) {
            entry.existing_key = layer.existingKey;
        }

        config.push(entry);
    }

    document.getElementById('f-layers-config').value = JSON.stringify(config);
});

// ── Save-as-template modal ────────────────────────────────────────────────────
document.getElementById('btn-save-template').addEventListener('click', () => {
    if (!selectedModel) return;

    // Populate hidden fields
    document.getElementById('tf-model-id').value      = selectedModel.id;
    document.getElementById('tf-model-color').value   = document.getElementById('model-color').value;
    document.getElementById('tf-support-mode').value  = document.getElementById('f-support-mode').value;
    document.getElementById('tf-support-angle').value = document.getElementById('f-support-angle').value;
    document.getElementById('tf-brim-width').value    = document.getElementById('f-brim-width').value;
    document.getElementById('tf-layer-height').value  = document.getElementById('f-layer-height').value;

    // Build template layers JSON
    const tmplLayers = [];
    let   fileIdx    = 0;
    const form       = document.getElementById('tmpl-form');

    // Remove any previously appended file inputs
    form.querySelectorAll('input[name^="template_file_"]').forEach(el => el.remove());

    for (const [id, layer] of layers) {
        if (!layer.hasFile) continue;

        const placement = editor?.getLayerPlacement(id) ?? null;
        const entry = {
            type:        layer.type,
            name:        layer.name,
            color:       layer.color,
            size:        layer.size,
            rotation:    layer.rotation,
            placement,
        };

        if (layer.type === 'logo') {
            if (layer.blobFile) {
                entry.file_index = fileIdx;
                const inp = document.createElement('input');
                inp.type = 'file'; inp.name = `template_file_${fileIdx}`; inp.style.display = 'none';
                const dt = new DataTransfer(); dt.items.add(layer.blobFile); inp.files = dt.files;
                form.appendChild(inp);
                fileIdx++;
            } else if (layer.existingKey) {
                entry.existing_key = layer.existingKey;
            }
        } else if (layer.type === 'text') {
            entry.text_content = layer.textContent;
            entry.font_family  = layer.fontFamily;
            entry.font_size    = layer.fontSize;
            entry.text_bold    = layer.textBold;
            entry.text_italic  = layer.textItalic;
            // No file upload for text — regenerated from settings
        }

        tmplLayers.push(entry);
    }

    document.getElementById('tf-layers').value = JSON.stringify(tmplLayers);

    const modal = document.getElementById('tmpl-modal');
    modal.style.display = 'flex';
    document.getElementById('tf-name').focus();
});

document.getElementById('btn-tmpl-cancel').addEventListener('click', () => {
    document.getElementById('tmpl-modal').style.display = 'none';
});
document.getElementById('tmpl-modal').addEventListener('click', function (e) {
    if (e.target === this) this.style.display = 'none';
});

// ── Template data (pre-loaded from PHP) ──────────────────────────────────────
const TEMPLATE_DATA = <?= json_encode($templateData) ?>;

async function loadTemplateData() {
    if (!TEMPLATE_DATA?.layers?.length) return;

    const ps = TEMPLATE_DATA.print_settings || {};
    // Apply print settings
    if (ps.support_mode)  document.getElementById('f-support-mode').value  = ps.support_mode;
    if (ps.support_angle) {
        document.getElementById('f-support-angle').value = ps.support_angle;
        document.getElementById('angle-val').textContent  = ps.support_angle;
    }
    if (ps.brim_width !== undefined) document.getElementById('f-brim-width').value  = ps.brim_width;
    if (ps.layer_height)              document.getElementById('f-layer-height').value = ps.layer_height;

    if (TEMPLATE_DATA.model_color) {
        document.getElementById('model-color').value = TEMPLATE_DATA.model_color;
        document.getElementById('f-model-color').value = TEMPLATE_DATA.model_color;
        if (editor) editor.setModelColor(TEMPLATE_DATA.model_color);
    }

    for (const tl of TEMPLATE_DATA.layers) {
        layerCounter++;
        const id   = `layer-${layerCounter}`;
        const name = tl.name || (tl.type === 'logo' ? `Logo ${layerCounter}` : `Text ${layerCounter}`);
        const layerEntry = {
            id, type: tl.type, name,
            blobFile:    null,
            existingKey: tl.type === 'logo' ? (tl.key || null) : null,
            blobUrl:     tl.type === 'logo' && tl.key
                             ? `/api/file?bucket=logos&key=${encodeURIComponent(tl.key)}`
                             : null,
            color:       tl.color       || '#ffffff',
            size:        tl.size        || 0.3,
            rotation:    tl.rotation    || 0,
            hasFile:     tl.type === 'logo' ? !!tl.key : !!tl.text_content,
            placed:      !!(tl.placement?.position),
            // text-specific
            textContent: tl.text_content || '',
            fontSize:    tl.font_size    || 60,
            fontFamily:  tl.font_family  || 'Arial',
            textBold:    !!tl.text_bold,
            textItalic:  !!tl.text_italic,
        };
        layers.set(id, layerEntry);

        if (editor) {
            editor.addLayer(id, layerEntry.color);
            editor.setLayerSize(id, layerEntry.size);
            editor.setLayerRotation(id, layerEntry.rotation);

            if (tl.type === 'logo' && tl.key) {
                editor.setLayerLogo(id, layerEntry.blobUrl);
            } else if (tl.type === 'text' && tl.text_content) {
                const srcCanvas = renderTextCanvas(layerEntry);
                if (srcCanvas) {
                    await new Promise(resolve => srcCanvas.toBlob(blob => {
                        layerEntry.blobFile = new File([blob], 'text.png', { type: 'image/png' });
                        if (layerEntry.blobUrl) URL.revokeObjectURL(layerEntry.blobUrl);
                        layerEntry.blobUrl  = URL.createObjectURL(blob);
                        editor.setLayerLogo(id, layerEntry.blobUrl);
                        resolve();
                    }, 'image/png'));
                }
            }

            if (tl.placement?.position) {
                // Slight delay to let texture load first
                setTimeout(() => {
                    editor.restoreLayerPlacement(id, tl.placement);
                    editor.setLayerSize(id, layerEntry.size);
                    editor.setLayerRotation(id, layerEntry.rotation);
                }, 300);
            }
        }
    }

    const lastId = [...layers.keys()].pop();
    if (lastId) activateLayer(lastId);

    renderLayerList();
    updateHints();
}

// ── Print direction ───────────────────────────────────────────────────────────
document.getElementById('f-print-direction').addEventListener('change', function () {
    if (!editor) return;
    const hadPlaced = editor.setPrintDirection(this.value);
    if (hadPlaced) {
        for (const layer of layers.values()) layer.placed = false;
        renderLayerList();
        document.getElementById('placement-status').textContent =
            'Druckrichtung geändert — Ebenen bitte neu platzieren.';
        updateHints();
    }
});

// ── Print settings controls ───────────────────────────────────────────────────
document.getElementById('f-support-angle').addEventListener('input', function () {
    document.getElementById('angle-val').textContent = this.value;
});
document.getElementById('f-support-mode').addEventListener('change', function () {
    document.getElementById('support-angle-group').style.display =
        this.value === 'none' ? 'none' : '';
});

// ── Pre-loaded logo from SVG converter ────────────────────────────────────────

const preLogoKey = <?= json_encode($prelogoKey) ?>;
if (preLogoKey) {
    addLayer('logo');
    const layer = layers.get(activeLayerId);
    layer.existingKey = preLogoKey;
    layer.hasFile     = true;

    // Defer logo load into editor until editor is ready
    const _origShow = showEditor;
    Object.defineProperty(window, '__preLogoKey', { value: preLogoKey });
    const logoUrl = `/api/file?bucket=logos&key=${encodeURIComponent(preLogoKey)}`;
    const pollForEditor = setInterval(() => {
        if (editor) {
            clearInterval(pollForEditor);
            editor.setLayerLogo(activeLayerId, logoUrl);
            layer.blobUrl = logoUrl;
        }
    }, 200);

    renderLayerList();
    updateHints();
}
</script>

<?php $content = ob_get_clean();
require __DIR__ . '/../layouts/base.php'; ?>
