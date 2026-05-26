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
.lib-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; }
.model-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:1rem; }
.model-card { background:#16213e; border:2px solid #2d3748; border-radius:10px; overflow:hidden;
              transition:border-color .2s; display:flex; flex-direction:column; }
.model-card:hover { border-color:#3b82f6; }
.model-card canvas { width:100%; height:150px; display:block; }
.model-card-body   { padding:.85rem; flex:1; display:flex; flex-direction:column; gap:.5rem; }
.model-name   { font-weight:700; font-size:.9rem; word-break:break-all; }
.model-meta   { font-size:.78rem; color:#64748b; }
.model-actions{ display:flex; gap:.5rem; margin-top:auto; }
.empty-state  { text-align:center; padding:4rem 1rem; color:#4b5563; }
.empty-state p { margin-bottom:1rem; }
</style>

<div class="lib-header">
    <h2 style="font-size:1.3rem;">3D-Bibliothek</h2>
    <a href="/models/new" class="btn btn-primary">+ 3D-Modell hochladen</a>
</div>

<?php if (empty($models)): ?>
    <div class="empty-state card">
        <p>Noch keine 3D-Modelle in der Bibliothek.</p>
        <a href="/models/new" class="btn btn-primary">Erstes Modell hochladen</a>
    </div>
<?php else: ?>
<div class="model-grid">
    <?php foreach ($models as $m): ?>
    <div class="model-card">
        <canvas id="canvas-<?= htmlspecialchars($m['id']) ?>"
                data-key="<?= htmlspecialchars($m['storage_key']) ?>"
                data-fmt="<?= htmlspecialchars($m['format']) ?>"
                data-plate="<?= htmlspecialchars($m['plate_index'] ?? '') ?>"
                data-filter="<?= htmlspecialchars($m['filter_objects'] ?? '') ?>"></canvas>
        <div class="model-card-body">
            <div class="model-name"><?= htmlspecialchars($m['name'] ?: $m['filename']) ?></div>
            <div class="model-meta">
                <?= strtoupper(htmlspecialchars($m['format'])) ?> &middot;
                <?= date('d.m.Y', strtotime($m['uploaded_at'])) ?>
            </div>
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem;">
                <label style="font-size:.75rem;color:#64748b;white-space:nowrap;">Farbe</label>
                <input type="color" class="color-pick" value="#4a9eff"
                       style="flex:1;height:1.6rem;border:none;border-radius:4px;cursor:pointer;background:none;">
            </div>
            <div class="model-actions">
                <a href="/jobs/new?model_id=<?= urlencode($m['id']) ?>" class="btn btn-primary" style="flex:1;justify-content:center;">Im Job verwenden</a>
                <form method="POST" action="/models/<?= urlencode($m['id']) ?>/delete"
                      onsubmit="return confirm('Modell löschen?')">
                    <button type="submit" class="btn btn-danger">&#x1F5D1;</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script type="module">
import { ModelViewer } from '@app/viewer';

const io = new IntersectionObserver((entries) => {
    for (const e of entries) {
        if (!e.isIntersecting) continue;
        const canvas = e.target;
        io.unobserve(canvas);

        const viewer = new ModelViewer(canvas, { background: 0x16213e });
        canvas._viewer = viewer;
        const url           = `/api/file?bucket=models&key=${encodeURIComponent(canvas.dataset.key)}`;
        const filterObjects = canvas.dataset.filter ? JSON.parse(canvas.dataset.filter) : null;
        const plateIndex    = canvas.dataset.plate  ? parseInt(canvas.dataset.plate)    : null;
        const opts          = filterObjects ? { filterObjectIds: filterObjects }
                            : plateIndex    ? { plateIndex }
                            : {};
        viewer.loadModel(url, canvas.dataset.fmt, opts).catch(err => {
            canvas.style.background = '#111';
            console.warn('Viewer error:', err);
        });
    }
}, { threshold: 0.1 });

document.querySelectorAll('[id^="canvas-"]').forEach(c => io.observe(c));

// Per-card colour pickers
document.querySelectorAll('.color-pick').forEach(input => {
    input.addEventListener('input', function () {
        const canvas = this.closest('.model-card').querySelector('canvas');
        if (canvas?._viewer) canvas._viewer.setModelColor(this.value);
    });
});
</script>

<?php $content = ob_get_clean();
require __DIR__ . '/../layouts/base.php'; ?>
