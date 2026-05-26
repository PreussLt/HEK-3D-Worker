<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HEK 3D Logo Worker</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body   { font-family: system-ui, sans-serif; background: #0f1117; color: #e2e8f0; min-height: 100vh; }
        header { background: #1a1a2e; border-bottom: 1px solid #2d3748; padding: .9rem 2rem;
                 display: flex; align-items: center; gap: 1.5rem; }
        header h1  { font-size: 1.1rem; font-weight: 700; letter-spacing: .03em; color: #fff; }
        header nav { display: flex; gap: .2rem; }
        header nav a { color: #94a3b8; text-decoration: none; padding: .4rem .75rem;
                       border-radius: 5px; font-size: .9rem; transition: background .15s, color .15s; }
        header nav a:hover, header nav a.active { background: #2d3748; color: #fff; }
        main   { max-width: 1100px; margin: 2rem auto; padding: 0 1.5rem; }

        /* Cards */
        .card  { background: #1e2433; border: 1px solid #2d3748; border-radius: 10px;
                 padding: 1.5rem; margin-bottom: 1.5rem; }

        /* Buttons */
        .btn   { display: inline-flex; align-items: center; gap: .4rem;
                 padding: .45rem 1.1rem; border-radius: 6px; border: none; cursor: pointer;
                 font-size: .88rem; font-weight: 600; text-decoration: none; transition: opacity .15s; }
        .btn:hover  { opacity: .85; }
        .btn-primary  { background: #3b82f6; color: #fff; }
        .btn-danger   { background: #ef4444; color: #fff; }
        .btn-ghost    { background: #2d3748; color: #e2e8f0; }

        /* Tables */
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: .6rem 1rem; text-align: left; border-bottom: 1px solid #2d3748; font-size: .9rem; }
        th  { background: #16213e; font-weight: 600; color: #94a3b8; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; }
        tr:hover td { background: #1a2035; }

        /* Badges */
        .badge { display: inline-block; padding: .2rem .65rem; border-radius: 999px; font-size: .78rem; font-weight: 700; }
        .badge-pending    { background: #451a03; color: #fb923c; }
        .badge-processing { background: #1e3a5f; color: #60a5fa; }
        .badge-done       { background: #14532d; color: #4ade80; }
        .badge-error      { background: #450a0a; color: #f87171; }

        /* Forms */
        label span { display: block; font-size: .85rem; font-weight: 600; margin-bottom: .3rem; color: #94a3b8; }
        input[type=file], input[type=text] {
            width: 100%; background: #111827; border: 1px solid #374151; border-radius: 6px;
            color: #e2e8f0; padding: .5rem .75rem; font-size: .9rem; }
        input[type=range] { width: 100%; accent-color: #3b82f6; }
    </style>
</head>
<body>
<header>
    <h1>HEK 3D</h1>
    <nav>
        <?php $cur = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); ?>
        <a href="/"        class="<?= $cur === '/'       ? 'active' : '' ?>">Jobs</a>
        <a href="/jobs/new" class="<?= $cur === '/jobs/new' ? 'active' : '' ?>">Neuer Job</a>
        <a href="/magnet"  class="<?= str_starts_with($cur, '/magnet') ? 'active' : '' ?>"
           style="<?= str_starts_with($cur, '/magnet') ? '' : 'color:#a78bfa;' ?>">Magnetlöcher</a>
        <a href="/models"  class="<?= str_starts_with($cur, '/models')  ? 'active' : '' ?>">Bibliothek</a>
        <a href="/templates" class="<?= str_starts_with($cur, '/templates') ? 'active' : '' ?>"
           style="<?= str_starts_with($cur, '/templates') ? '' : 'color:#fbbf24;' ?>">Vorlagen</a>
        <a href="/convert" class="<?= str_starts_with($cur, '/convert') ? 'active' : '' ?>"
           style="<?= str_starts_with($cur, '/convert') ? '' : 'color:#34d399;' ?>">SVG Konverter</a>
        <a href="/editor"  class="<?= str_starts_with($cur, '/editor')  ? 'active' : '' ?>"
           style="<?= str_starts_with($cur, '/editor')  ? '' : 'color:#fb923c;' ?>">3D Editor</a>
    </nav>
</header>
<main>
    <?= $content ?? '' ?>
</main>
</body>
</html>
