<?php ob_start(); ?>

<style>
.svg-preview { background:#fff; border-radius:8px; border:1px solid #374151;
               display:flex; align-items:center; justify-content:center;
               min-height:300px; overflow:hidden; padding:1.5rem; }
.svg-preview svg, .svg-preview img { max-width:100%; max-height:400px; }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.2rem;">
    <h2 style="font-size:1.3rem;">SVG-Konvertierung</h2>
    <span class="badge badge-<?= htmlspecialchars($job['status'] ?? 'pending') ?>">
        <?= htmlspecialchars($job['status'] ?? '—') ?>
    </span>
</div>

<?php if (!$job): ?>
    <div class="card"><p>Job nicht gefunden.</p></div>

<?php elseif ($job['status'] === 'done' && $job['output_key']): ?>

<div style="display:grid;grid-template-columns:1fr 280px;gap:1.5rem;align-items:start;">
    <div class="card" style="padding:1rem;">
        <h3 style="font-size:.95rem;margin-bottom:.75rem;">Vorschau</h3>
        <div class="svg-preview" id="svg-preview">
            <img src="/api/file?bucket=output&key=<?= urlencode($job['output_key']) ?>"
                 alt="SVG Vorschau">
        </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:1rem;">
        <div class="card">
            <h3 style="font-size:.95rem;margin-bottom:.9rem;">Herunterladen</h3>
            <a href="/api/file?bucket=output&key=<?= urlencode($job['output_key']) ?>"
               download="<?= htmlspecialchars(pathinfo($job['filename'], PATHINFO_FILENAME)) ?>.svg"
               class="btn btn-primary" style="width:100%;justify-content:center;background:#7c3aed;margin-bottom:.6rem;">
               SVG herunterladen
            </a>
            <?php if (!empty($job['logo_key'])): ?>
            <a href="/jobs/new?logo_key=<?= urlencode($job['logo_key']) ?>"
               class="btn btn-primary" style="width:100%;justify-content:center;background:#059669;">
               Als Logo für Print-On verwenden
            </a>
            <?php endif; ?>
        </div>
        <div class="card">
            <table style="font-size:.85rem;">
                <tr><th style="width:100px;">Datei</th>
                    <td><?= htmlspecialchars($job['filename']) ?></td></tr>
                <tr><th>Fertig</th>
                    <td><?= htmlspecialchars(substr($job['updated_at'], 0, 16)) ?></td></tr>
            </table>
        </div>
        <a href="/convert" class="btn btn-ghost" style="justify-content:center;">← Konverter</a>
    </div>
</div>

<?php else: ?>

<div class="card">
    <table style="font-size:.9rem;max-width:600px;">
        <tr><th style="width:120px;">ID</th>
            <td style="word-break:break-all;"><?= htmlspecialchars($job['id']) ?></td></tr>
        <tr><th>Datei</th>
            <td><?= htmlspecialchars($job['filename']) ?></td></tr>
        <tr><th>Status</th>
            <td><span class="badge badge-<?= htmlspecialchars($job['status']) ?>">
                <?= htmlspecialchars($job['status']) ?></span></td></tr>
        <?php if ($job['error']): ?>
        <tr><th>Fehler</th>
            <td style="color:#f87171;"><?= htmlspecialchars($job['error']) ?></td></tr>
        <?php endif; ?>
    </table>

    <?php if (in_array($job['status'], ['pending', 'processing'])): ?>
    <p style="margin-top:1rem;font-size:.85rem;color:#60a5fa;">
        ⏳ Wird konvertiert — Seite aktualisiert automatisch…
    </p>
    <script>setTimeout(() => location.reload(), 3000);</script>
    <?php endif; ?>

    <div style="margin-top:1rem;"><a href="/convert" class="btn btn-ghost">← Konverter</a></div>
</div>

<?php endif; ?>

<?php $content = ob_get_clean();
require __DIR__ . '/../layouts/base.php'; ?>
