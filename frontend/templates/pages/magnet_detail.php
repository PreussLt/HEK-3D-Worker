<?php ob_start(); ?>

<style>
.mag-result { display:grid; grid-template-columns:1fr 320px; gap:1.5rem; align-items:start; }
@media(max-width:700px){ .mag-result { grid-template-columns:1fr; } }

.dl-btn   { display:flex; align-items:center; gap:.6rem; padding:.6rem 1rem;
            border-radius:8px; border:none; cursor:pointer; font-size:.88rem; font-weight:700;
            text-decoration:none; transition:opacity .15s; }
.dl-btn:hover { opacity:.85; }
.dl-model    { background:#7c3aed; color:#fff; }
.dl-template { background:#059669; color:#fff; }

.spec-row { display:flex; justify-content:space-between; padding:.45rem 0;
            border-bottom:1px solid #2d3748; font-size:.88rem; }
.spec-row:last-child { border-bottom:none; }
.spec-key { color:#94a3b8; font-weight:600; }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.2rem;">
    <h2 style="font-size:1.3rem;">Magnetlöcher — Job-Detail</h2>
    <?php if ($job): ?>
    <span class="badge badge-<?= htmlspecialchars($job['status']) ?>">
        <?= htmlspecialchars($job['status']) ?>
    </span>
    <?php endif; ?>
</div>

<?php if (!$job): ?>
    <div class="card"><p>Job nicht gefunden.</p></div>

<?php elseif ($job['status'] === 'done'): ?>

<div class="mag-result">
    <!-- Downloads -->
    <div>
        <div class="card">
            <h3 style="font-size:.95rem;margin-bottom:1rem;">Downloads</h3>

            <div style="display:flex;flex-direction:column;gap:.75rem;">
                <?php if ($job['output_key']): ?>
                <a href="/api/file?bucket=output&key=<?= urlencode($job['output_key']) ?>"
                   download="magnets_<?= htmlspecialchars($job['id']) ?>.stl"
                   class="dl-btn dl-model">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5">
                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Modell mit Magnetlöchern (.stl)
                </a>
                <?php endif; ?>

                <?php if ($job['template_key']): ?>
                <a href="/api/file?bucket=output&key=<?= urlencode($job['template_key']) ?>"
                   download="schablone_<?= htmlspecialchars($job['id']) ?>.stl"
                   class="dl-btn dl-template">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5">
                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Bohrschablone drucken (.stl)
                </a>
                <?php endif; ?>
            </div>

            <div style="margin-top:1.1rem;background:#111827;border-radius:6px;
                        padding:.85rem;font-size:.8rem;line-height:1.8;color:#94a3b8;">
                <strong style="color:#c4b5fd;display:block;margin-bottom:.4rem;">
                    Schablone verwenden:
                </strong>
                1. Schablone aus dem STL drucken<br>
                2. Auf die ebene Fläche des Modells legen<br>
                3. Spiralbohrer (Ø <?= htmlspecialchars(number_format((float)$job['magnet_diameter'], 1)) ?> mm)
                   durch die Löcher bohren<br>
                4. Magneten einpressen
            </div>
        </div>
    </div>

    <!-- Job info -->
    <div style="display:flex;flex-direction:column;gap:1rem;">
        <div class="card">
            <h3 style="font-size:.95rem;margin-bottom:.85rem;">Parameter</h3>

            <div class="spec-row">
                <span class="spec-key">Durchmesser</span>
                <span><?= htmlspecialchars(number_format((float)$job['magnet_diameter'], 1)) ?> mm</span>
            </div>
            <div class="spec-row">
                <span class="spec-key">Tiefe</span>
                <span><?= htmlspecialchars(number_format((float)$job['magnet_length'], 1)) ?> mm</span>
            </div>
            <div class="spec-row">
                <span class="spec-key">Modell</span>
                <span style="word-break:break-all;font-size:.8rem;">
                    <?= htmlspecialchars(basename($job['model_key'])) ?>
                </span>
            </div>
            <div class="spec-row">
                <span class="spec-key">Fertig</span>
                <span><?= htmlspecialchars(substr($job['updated_at'], 0, 16)) ?></span>
            </div>
        </div>

        <a href="/magnet" class="btn btn-ghost" style="justify-content:center;">
            ← Alle Magnet-Jobs
        </a>
        <a href="/magnet/new" class="btn btn-ghost" style="justify-content:center;background:#4c1d95;color:#e9d5ff;">
            + Neuer Magnet-Job
        </a>
    </div>
</div>

<?php else: ?>

<div class="card">
    <table style="font-size:.9rem;max-width:600px;">
        <tr><th style="width:130px;">ID</th>
            <td style="word-break:break-all;"><?= htmlspecialchars($job['id']) ?></td></tr>
        <tr><th>Status</th>
            <td><span class="badge badge-<?= htmlspecialchars($job['status']) ?>">
                <?= htmlspecialchars($job['status']) ?></span></td></tr>
        <tr><th>Modell</th>
            <td><?= htmlspecialchars(basename($job['model_key'])) ?></td></tr>
        <tr><th>Durchmesser</th>
            <td><?= htmlspecialchars(number_format((float)$job['magnet_diameter'], 1)) ?> mm</td></tr>
        <tr><th>Tiefe</th>
            <td><?= htmlspecialchars(number_format((float)$job['magnet_length'], 1)) ?> mm</td></tr>
        <?php if ($job['error']): ?>
        <tr><th>Fehler</th>
            <td style="color:#f87171;"><?= htmlspecialchars($job['error']) ?></td></tr>
        <?php endif; ?>
        <tr><th>Erstellt</th>
            <td><?= htmlspecialchars($job['created_at']) ?></td></tr>
    </table>

    <?php if ($job['status'] === 'processing'): ?>
    <p style="margin-top:1rem;font-size:.85rem;color:#60a5fa;">
        ⏳ Bohrlöcher werden berechnet — Seite wird automatisch neu geladen…
    </p>
    <script>setTimeout(() => location.reload(), 4000);</script>
    <?php endif; ?>

    <div style="margin-top:1rem;display:flex;gap:.75rem;">
        <a href="/magnet" class="btn btn-ghost">← Alle Magnet-Jobs</a>
        <a href="/magnet/new" class="btn btn-ghost" style="background:#4c1d95;color:#e9d5ff;">
            + Neuer Job
        </a>
    </div>
</div>

<?php endif; ?>

<?php $content = ob_get_clean();
require __DIR__ . '/../layouts/base.php'; ?>
