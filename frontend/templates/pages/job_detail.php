<?php ob_start(); ?>

<?php if ($job && $job['status'] === 'done' && $job['output_key']): ?>
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
<?php endif; ?>

<style>
.result-layout { display:grid; grid-template-columns:1fr 320px; gap:1.5rem; align-items:start; }
@media(max-width:700px){ .result-layout { grid-template-columns:1fr; } }
#result-canvas { width:100%; height:440px; display:block; border-radius:8px;
                 border:1px solid #2d3748; background:#111827; }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.2rem;">
    <h2 style="font-size:1.3rem;">Job-Detail</h2>
    <span class="badge badge-<?= htmlspecialchars($job['status']) ?>">
        <?= htmlspecialchars($job['status']) ?>
    </span>
</div>

<?php if (!$job): ?>
    <div class="card"><p>Job nicht gefunden.</p></div>

<?php elseif ($job['status'] === 'done' && $job['output_key']): ?>

<div class="result-layout">
    <!-- 3D Preview of the result -->
    <div class="card" style="padding:1rem;">
        <h3 style="font-size:.95rem;margin-bottom:.75rem;">Ergebnis-Vorschau</h3>
        <canvas id="result-canvas"></canvas>
    </div>

    <!-- Info + Download -->
    <div style="display:flex;flex-direction:column;gap:1rem;">
        <div class="card">
            <h3 style="font-size:.95rem;margin-bottom:.9rem;">Exportieren</h3>
            <?php
                $dlBase = $job['model_name'] ?? ($job['model_filename'] ?? null);
                if (!$dlBase) $dlBase = 'print_' . $job['id'];
                $dlName = preg_replace('/\.[^.]+$/', '', $dlBase) . '.3mf';
            ?>
            <a href="/api/file?bucket=output&key=<?= urlencode($job['output_key']) ?>"
               download="<?= htmlspecialchars($dlName) ?>"
               class="btn btn-primary" style="width:100%;justify-content:center;">
               3MF herunterladen
            </a>
            <div style="margin-top:.9rem;background:#111827;border-radius:6px;padding:.75rem;font-size:.78rem;line-height:1.7;">
                <strong style="color:#94a3b8;">Objekte in der Datei:</strong><br>
                <span style="color:<?= htmlspecialchars($job['model_color'] ?? '#4a9eff') ?>">■</span>
                <strong>Modell</strong> — <?= htmlspecialchars($job['model_color'] ?? '—') ?><br>
                <span style="color:<?= htmlspecialchars($job['logo_color'] ?? '#ffffff') ?>">■</span>
                <strong>Logo</strong> &nbsp;— <?= htmlspecialchars($job['logo_color'] ?? '—') ?><br>
                <hr style="border-color:#2d3748;margin:.5rem 0;">
                <strong style="color:#94a3b8;">Import-Anleitung:</strong><br>
                3MF öffnen → 2 Objekte erscheinen:<br>
                <code>Grundkörper</code> → Filament 1<br>
                <code>Logo-Einlage</code> → Filament 2<br>
                Das Logo ist als Negativ in den Grundkörper eingearbeitet. Die Einlage füllt die Tasche mit der Logo-Farbe.
            </div>
        </div>

        <div class="card">
            <table style="font-size:.85rem;">
                <tr><th style="width:110px;">ID</th>
                    <td style="word-break:break-all;"><?= htmlspecialchars($job['id']) ?></td></tr>
                <tr><th>Farbe</th>
                    <td>
                        <span style="display:inline-block;width:14px;height:14px;border-radius:3px;
                                     background:<?= htmlspecialchars($job['model_color'] ?? '#4a9eff') ?>;
                                     vertical-align:middle;margin-right:.4rem;border:1px solid #374151;"></span>
                        <?= htmlspecialchars($job['model_color'] ?? '—') ?>
                    </td></tr>
                <tr><th>Logo</th>
                    <td><?= htmlspecialchars(basename($job['logo_key'])) ?></td></tr>
                <tr><th>Modell</th>
                    <td><?= htmlspecialchars(basename($job['model_key'])) ?></td></tr>
                <tr><th>Fertig</th>
                    <td><?= htmlspecialchars(substr($job['updated_at'], 0, 16)) ?></td></tr>
            </table>
        </div>

        <a href="/" class="btn btn-ghost" style="justify-content:center;">← Alle Jobs</a>
    </div>
</div>

<script type="module">
import { ModelViewer } from '@app/viewer';

requestAnimationFrame(() => requestAnimationFrame(() => {
    const canvas = document.getElementById('result-canvas');
    const viewer = new ModelViewer(canvas, { background: 0x111827 });
    viewer.forceResize();
    // Preview the exported 3MF directly — shows both model + logo geometry
    viewer.loadModel(
        '/api/file?bucket=output&key=<?= urlencode($job['output_key']) ?>',
        '3mf'
    ).catch(err => {
        // Fallback to original model on error
        viewer.loadModel(
            '/api/file?bucket=models&key=<?= urlencode($job['model_key']) ?>',
            '<?= strtolower(pathinfo($job['model_key'], PATHINFO_EXTENSION)) ?>'
        ).then(() => viewer.setModelColor('<?= htmlspecialchars($job['model_color'] ?? '#4a9eff') ?>'))
         .catch(console.error);
    });
}));
</script>

<?php else: ?>

<div class="card">
    <table style="font-size:.9rem;max-width:600px;">
        <tr><th style="width:120px;">ID</th>
            <td><?= htmlspecialchars($job['id']) ?></td></tr>
        <tr><th>Status</th>
            <td><span class="badge badge-<?= htmlspecialchars($job['status']) ?>">
                <?= htmlspecialchars($job['status']) ?></span></td></tr>
        <tr><th>Logo</th>
            <td><?= htmlspecialchars(basename($job['logo_key'])) ?></td></tr>
        <tr><th>Modell</th>
            <td><?= htmlspecialchars(basename($job['model_key'])) ?></td></tr>
        <tr><th>Farbe</th>
            <td>
                <?php if (!empty($job['model_color'])): ?>
                <span style="display:inline-block;width:14px;height:14px;border-radius:3px;
                             background:<?= htmlspecialchars($job['model_color']) ?>;
                             vertical-align:middle;margin-right:.4rem;border:1px solid #374151;"></span>
                <?= htmlspecialchars($job['model_color']) ?>
                <?php else: ?>—<?php endif; ?>
            </td></tr>
        <?php if ($job['error']): ?>
        <tr><th>Fehler</th>
            <td style="color:#f87171;"><?= htmlspecialchars($job['error']) ?></td></tr>
        <?php endif; ?>
        <tr><th>Erstellt</th>
            <td><?= htmlspecialchars($job['created_at']) ?></td></tr>
    </table>

    <?php if ($job['status'] === 'processing'): ?>
    <p style="margin-top:1rem;font-size:.85rem;color:#60a5fa;">
        ⏳ Wird verarbeitet — Seite neu laden um den Status zu aktualisieren.
    </p>
    <script>setTimeout(() => location.reload(), 4000);</script>
    <?php endif; ?>

    <div style="margin-top:1rem;"><a href="/">← Alle Jobs</a></div>
</div>

<?php endif; ?>

<?php $content = ob_get_clean();
require __DIR__ . '/../layouts/base.php'; ?>
