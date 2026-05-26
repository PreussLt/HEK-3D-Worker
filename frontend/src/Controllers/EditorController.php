<?php

namespace App\Controllers;

use App\Services\DatabaseService;
use App\Services\StorageService;

class EditorController
{
    public function __construct(
        private DatabaseService $db,
        private StorageService  $storage
    ) {}

    public function index(): void
    {
        require __DIR__ . '/../../templates/pages/editor.php';
    }

    public function save(): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $file = $_FILES['model'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'Upload fehlgeschlagen']);
            return;
        }

        $name     = trim($_POST['name'] ?? 'Editor-Modell');
        $filename = preg_replace('/[^a-zA-Z0-9_\-äöüÄÖÜß ]/', '', $name) . '.stl';
        $key      = uniqid() . '_' . $filename;

        $this->storage->upload('models', $key, $file['tmp_name']);
        $id = $this->db->createModel($filename, 'stl', $key);

        echo json_encode(['ok' => true, 'id' => $id]);
    }
}
