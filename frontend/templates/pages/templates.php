<?php ob_start(); ?>

<script src="https://unpkg.com/jszip@3.10.1/dist/jszip.min.js"></script>
<?php $vv = filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/viewer.js'); ?>
<script type="importmap">
{
  "imports": {
    "three":       "https://unpkg.com/three@0.167.0/build/three.module.js",
    "three/addons/": "https://unpkg.com/three@0.167.0/examples/jsm/",
    "@app/viewer": "/assets/js/viewer.js?v=<?= $vv ?>"
  }
}
</script>

<style>
.tmpl-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; }
.tmpl-grid   { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:1.2rem; }
.tmpl-card   { background:#16213e; border:2px solid #2d3748; border-radius:10px; overflow:hidden;
               display:flex; flex-direction:column; transition:border-color .2s; }
.tmpl-card:hover { border-color:#fbbf24; }
.tmpl-thumb  { background:#111827; height:140px; position:relative; overflow:hidden; }
.tmpl-thumb canvas { width:100%; height:100%; display:block; }
.tmpl-body   { padding:.9rem; display:flex; flex-direction:column; gap:.5rem; flex:1; }
.tmpl-name   { font-weight:700; font-size:.95rem; }
.tmpl-meta   { font-size:.76rem; color:#64748b; }
.tmpl-layers { display:flex; flex-wrap:wrap; gap:.3rem; }
.tmpl-badge  { font-size:.68rem; font-weight:700; padding:.1rem .4rem; border-radius:3px; }
.tmpl-badge.logo { background:#3b82f6; color:#fff; }
.tmpl-badge.text { background:#8b5cf6; color:#fff; }
.tmpl-actions { display:flex; gap:.5rem; margin-top:auto; }
.empty-state { text-align:center; padding:4rem 1rem; color:#4b5563; }
</style>

<div class="tmpl-header">
    <h2 style="font-size:1.3rem;">Vorlagen</h2>
</div>

<?php if (empty($templates)): ?>
<div class="empty-state card">
    <p>Noch keine Vorlagen gespeichert.</p>
    <p style="margin-top:.5rem;font-size:.85rem;">
        Erstelle einen Job und klicke auf <strong>„Als Vorlage speichern"</strong>.
    </p>
</div>
<?php else: ?>
<div class="tmpl-grid">
    <?php foreach ($templates as $t):
        $layerArr = $t['layers'] ? json_decode($t['layers'], true) : [];
        $logoCount = count(array_filter($layerArr, fn($l) => ($l['type'] ?? '') === 'logo'));
        $textCount = count(array_filter($layerArr, fn($l) => ($l['type'] ?? '') === 'text'));
        $ps = $t['print_settings'] ? json_decode($t['print_settings'], true) : [];
    ?>
    <div class="tmpl-card">
        <div class="tmpl-thumb">
            <?php if ($t['model_key']): ?>
            <canvas id="tc-<?= htmlspecialchars($t['id']) ?>"
                    data-key="<?= htmlspecialchars($t['model_key']) ?>"
                    data-fmt="<?= htmlspecialchars($t['model_format'] ?? '') ?>"
                    data-plate="<?= htmlspecialchars($t['plate_index'] ?? '') ?>"
                    data-filter="<?= htmlspecialchars($t['filter_objects'] ?? '') ?>"></canvas>
            <?php else: ?>
            <div style="display:flex;align-items:center;justify-content:center;height:100%;color:#374151;font-size:.8rem;">Kein Modell</div>
            <?php endif; ?>
        </div>
        <div class="tmpl-body">
            <div class="tmpl-name"><?= htmlspecialchars($t['name']) ?></div>
            <div class="tmpl-meta">
                <?= htmlspecialchars($t['model_filename'] ?? '–') ?> &middot;
                <?= date('d.m.Y', strtotime($t['created_at'])) ?>
            </div>
            <div class="tmpl-meta">
                Stützen: <?= htmlspecialchars($ps['support_mode'] ?? 'auto') ?> &middot;
                Brim: <?= htmlspecialchars($ps['brim_width'] ?? 5) ?> mm &middot;
                <?= htmlspecialchars($ps['layer_height'] ?? 0.2) ?> mm/Layer
            </div>
            <?php if ($logoCount || $textCount): ?>
            <div class="tmpl-layers">
                <?php for ($i = 0; $i < $logoCount; $i++): ?>
                    <span class="tmpl-badge logo">L</span>
                <?php endfor; ?>
                <?php for ($i = 0; $i < $textCount; $i++): ?>
                    <span class="tmpl-badge text">T</span>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
            <div class="tmpl-actions">
                <a href="/jobs/new?template_id=<?= urlencode($t['id']) ?>"
                   class="btn btn-primary" style="flex:1;justify-content:center;">Verwenden</a>
                <form method="POST" action="/templates/<?= urlencode($t['id']) ?>/delete"
                      onsubmit="return confirm('Vorlage löschen?')">
                    <button type="submit" class="btn btn-danger">&#x1F5D1;</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script type="module">
import { ModelViewer } from '@app/viewer';

const io = new IntersectionObserver((entries) => {
    for (const e of entries) {
        if (!e.isIntersecting) continue;
        io.unobserve(e.target);
        const c = e.target;
        const v             = new ModelViewer(c, { background: 0x16213e });
        const filterObjects = c.dataset.filter ? JSON.parse(c.dataset.filter) : null;
        const plateIndex    = c.dataset.plate  ? parseInt(c.dataset.plate)    : null;
        const opts          = filterObjects ? { filterObjectIds: filterObjects }
                            : plateIndex    ? { plateIndex }
                            : {};
        v.loadModel(`/api/file?bucket=models&key=${encodeURIComponent(c.dataset.key)}`,
            c.dataset.fmt, opts).catch(() => {});
    }
}, { threshold: 0.1 });

document.querySelectorAll('[id^="tc-"]').forEach(c => io.observe(c));
</script>
<?php endif; ?>

<?php $content = ob_get_clean();
require __DIR__ . '/../layouts/base.php'; ?>
