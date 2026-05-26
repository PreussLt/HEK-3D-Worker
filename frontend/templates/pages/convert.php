<?php ob_start(); ?>

<style>
.drop-zone   { border:2px dashed #374151; border-radius:10px; padding:2.5rem 1.5rem;
               text-align:center; cursor:pointer; transition:border-color .2s,background .2s;
               background:#111827; }
.drop-zone:hover, .drop-zone.drag-over { border-color:#a78bfa; background:#1e1a3f; }
.drop-zone input { display:none; }
.drop-zone .icon { font-size:2.5rem; margin-bottom:.6rem; }
.drop-zone .hint { font-size:.85rem; color:#64748b; margin-top:.4rem; }

.convert-table th { width:120px; }
.badge-done       { background:#14532d; color:#4ade80; }
.badge-pending    { background:#1e3a5f; color:#60a5fa; }
.badge-processing { background:#1c1917; color:#fb923c; }
.badge-error      { background:#450a0a; color:#f87171; }
</style>

<h2 style="margin-bottom:1.2rem;font-size:1.3rem;">PNG / JPG → SVG Konverter</h2>

<div style="display:grid;grid-template-columns:1fr 320px;gap:1.5rem;align-items:start;">
    <div class="card">
        <h3 style="font-size:1rem;margin-bottom:.9rem;">Bild hochladen</h3>
        <form id="upload-form" method="POST" action="/convert/new" enctype="multipart/form-data">
            <div class="drop-zone" id="drop-zone">
                <div class="icon">🖼</div>
                <div>Datei hier ablegen oder <strong style="color:#a78bfa;">klicken</strong></div>
                <div class="hint">PNG, JPG, WEBP, BMP — max. 20 MB</div>
                <div id="file-name" style="margin-top:.6rem;color:#c4b5fd;font-size:.85rem;"></div>
                <input type="file" name="image" id="file-input" accept="image/png,image/jpeg,image/webp,image/bmp">
            </div>
            <button type="submit" id="submit-btn" class="btn btn-primary"
                    style="margin-top:1rem;width:100%;justify-content:center;background:#7c3aed;" disabled>
                Konvertieren
            </button>
        </form>
    </div>

    <div class="card" style="font-size:.82rem;color:#64748b;line-height:1.8;">
        <strong style="color:#94a3b8;display:block;margin-bottom:.4rem;">Wie funktioniert es?</strong>
        <span style="color:#a78bfa;">1.</span> Bild hochladen (PNG, JPG…)<br>
        <span style="color:#a78bfa;">2.</span> Worker erkennt Ränder (Vtracer)<br>
        <span style="color:#a78bfa;">3.</span> SVG mit glatten Pfaden herunterladen<br>
        <hr style="border-color:#2d3748;margin:.6rem 0;">
        <strong style="color:#94a3b8;">Tipp:</strong><br>
        Logos mit transparentem Hintergrund (PNG) ergeben die saubersten SVGs.
        Bei JPG wird der helle Hintergrund automatisch entfernt.
    </div>
</div>

<?php if (!empty($jobs)): ?>
<div class="card" style="margin-top:1.5rem;">
    <h3 style="font-size:1rem;margin-bottom:.85rem;">Letzte Konvertierungen</h3>
    <table style="font-size:.85rem;width:100%;">
        <thead>
            <tr><th>Dateiname</th><th>Status</th><th>Erstellt</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($jobs as $j): ?>
        <tr>
            <td><?= htmlspecialchars($j['filename']) ?></td>
            <td><span class="badge badge-<?= htmlspecialchars($j['status']) ?>"><?= htmlspecialchars($j['status']) ?></span></td>
            <td style="color:#64748b;"><?= htmlspecialchars(substr($j['created_at'], 0, 16)) ?></td>
            <td><a href="/convert/<?= htmlspecialchars($j['id']) ?>" style="color:#a78bfa;">→</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
const zone   = document.getElementById('drop-zone');
const input  = document.getElementById('file-input');
const btn    = document.getElementById('submit-btn');
const label  = document.getElementById('file-name');

zone.addEventListener('click', () => input.click());
zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.classList.remove('drag-over');
    const f = e.dataTransfer.files[0];
    if (f) setFile(f);
});
input.addEventListener('change', () => { if (input.files[0]) setFile(input.files[0]); });

function setFile(f) {
    const dt = new DataTransfer(); dt.items.add(f); input.files = dt.files;
    label.textContent = f.name + ' (' + (f.size / 1024).toFixed(0) + ' KB)';
    btn.disabled = false;
}
</script>

<?php $content = ob_get_clean();
require __DIR__ . '/../layouts/base.php'; ?>
