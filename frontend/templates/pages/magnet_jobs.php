<?php ob_start(); ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
        <h2>Magnet-Jobs</h2>
        <a href="/magnet/new" class="btn btn-primary" style="background:#7c3aed;">
            + Magnetlöcher berechnen
        </a>
    </div>

    <?php if (empty($jobs)): ?>
        <p style="color:#888;">
            Noch keine Magnet-Jobs.
            <a href="/magnet/new" style="color:#a78bfa;">Ersten Job erstellen →</a>
        </p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Status</th>
                <th>Modell</th>
                <th>Ø (mm)</th>
                <th>Tiefe (mm)</th>
                <th>Erstellt</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($jobs as $job): ?>
            <tr>
                <td>
                    <a href="/magnet/<?= htmlspecialchars($job['id']) ?>">
                        <?= substr($job['id'], 0, 8) ?>…
                    </a>
                </td>
                <td>
                    <span class="badge badge-<?= htmlspecialchars($job['status']) ?>">
                        <?= htmlspecialchars($job['status']) ?>
                    </span>
                </td>
                <td><?= htmlspecialchars(basename($job['model_key'])) ?></td>
                <td><?= htmlspecialchars(number_format((float)$job['magnet_diameter'], 1)) ?></td>
                <td><?= htmlspecialchars(number_format((float)$job['magnet_length'], 1)) ?></td>
                <td><?= htmlspecialchars(substr($job['created_at'], 0, 16)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php $content = ob_get_clean();
require __DIR__ . '/../layouts/base.php'; ?>
