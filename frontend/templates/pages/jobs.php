<?php ob_start(); ?>
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
        <h2>Jobs</h2>
        <a href="/jobs/new" class="btn btn-primary">+ Neuer Job</a>
    </div>
    <?php if (empty($jobs)): ?>
        <p style="color:#888;">Noch keine Jobs vorhanden. <a href="/jobs/new">Ersten Job erstellen</a>.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr><th>ID</th><th>Status</th><th>Logo</th><th>Modell</th><th>Erstellt</th></tr>
        </thead>
        <tbody>
        <?php foreach ($jobs as $job): ?>
            <tr>
                <td><a href="/jobs/<?= htmlspecialchars($job['id']) ?>"><?= substr($job['id'], 0, 8) ?>…</a></td>
                <td><span class="badge badge-<?= htmlspecialchars($job['status']) ?>"><?= htmlspecialchars($job['status']) ?></span></td>
                <td><?= htmlspecialchars(basename($job['logo_key'])) ?></td>
                <td><?= htmlspecialchars(basename($job['model_key'])) ?></td>
                <td><?= htmlspecialchars(substr($job['created_at'], 0, 16)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php $content = ob_get_clean();
require __DIR__ . '/../layouts/base.php'; ?>
