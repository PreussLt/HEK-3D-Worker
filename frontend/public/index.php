<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Controllers\ConvertController;
use App\Controllers\EditorController;
use App\Controllers\JobController;
use App\Controllers\MagnetController;
use App\Controllers\ModelController;
use App\Controllers\TemplateController;
use App\Services\DatabaseService;
use App\Services\StorageService;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

$db      = new DatabaseService(require __DIR__ . '/../config/database.php');
$storage = new StorageService(require __DIR__ . '/../config/storage.php');

$jobs      = new JobController($db, $storage);
$magnet    = new MagnetController($db, $storage);
$models    = new ModelController($db, $storage);
$convert   = new ConvertController($db, $storage);
$editor    = new EditorController($db, $storage);
$templates = new TemplateController($db, $storage);

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

match (true) {
    // Jobs
    $uri === '/'                                                    => $jobs->index(),
    $uri === '/jobs/new'                                            => $jobs->create(),
    (bool) preg_match('#^/jobs/([^/]+)$#', $uri, $m)              => $jobs->show($m[1]),

    // Magnet Jobs
    $uri === '/magnet'                                              => $magnet->index(),
    $uri === '/magnet/new'                                          => $magnet->create(),
    (bool) preg_match('#^/magnet/([^/]+)$#', $uri, $m)            => $magnet->show($m[1]),

    // Models
    $uri === '/models'                                              => $models->index(),
    $uri === '/models/new'                                          => $models->upload(),
    (bool) preg_match('#^/models/([^/]+)/delete$#', $uri, $m)     => $models->delete($m[1]),

    // SVG Converter
    $uri === '/convert'                                               => $convert->index(),
    $uri === '/convert/new'                                           => $convert->create(),
    (bool) preg_match('#^/convert/([^/]+)$#', $uri, $m)             => $convert->show($m[1]),

    // 3D Editor
    $uri === '/editor'                                                => $editor->index(),
    $uri === '/editor/save'                                           => $editor->save(),

    // Templates
    $uri === '/templates'                                             => $templates->index(),
    $uri === '/templates/new'                                         => $templates->create(),
    (bool) preg_match('#^/templates/([^/]+)/delete$#', $uri, $m)    => $templates->delete($m[1]),

    // File proxy: /api/file?bucket=models&key=models/abc.stl
    $uri === '/api/file'                                            => $models->stream(
        $_GET['bucket'] ?? '',
        $_GET['key']    ?? ''
    ),

    default => http_response_code(404),
};
