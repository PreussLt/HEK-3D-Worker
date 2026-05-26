<?php

namespace App\Controllers;

use App\Services\DatabaseService;
use App\Services\StorageService;

class ModelController
{
    public function __construct(
        private DatabaseService $db,
        private StorageService  $storage
    ) {}

    public function index(): void
    {
        $models = $this->db->listModels();
        require __DIR__ . '/../../templates/pages/models.php';
    }

    public function upload(): void
    {
        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $file    = $_FILES['model'] ?? null;
            $allowed = ['stl', 'obj', 'ply', 'glb', 'gltf', '3mf'];

            if ($file && $file['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                if (in_array($ext, $allowed)) {
                    $key        = uniqid() . '_' . basename($file['name']);
                    $plateIndex = isset($_POST['plate_index']) && $_POST['plate_index'] !== ''
                        ? (int)$_POST['plate_index'] : null;
                    $rawFilter  = trim($_POST['filter_objects'] ?? '');
                    $filterObjects = null;
                    if ($rawFilter !== '' && $rawFilter !== '[]') {
                        $decoded = json_decode($rawFilter, true);
                        if (is_array($decoded) && !empty($decoded)) {
                            $filterObjects = json_encode(array_values(array_map('strval', $decoded)));
                        }
                    }
                    $customName = trim($_POST['name'] ?? '');
                    $this->storage->upload('models', $key, $file['tmp_name']);
                    $this->db->createModel($file['name'], $ext, $key, $plateIndex, $filterObjects, $customName ?: null);
                    header('Location: /models');
                    exit;
                }

                $error = 'Ungültiges Format. Erlaubt: ' . implode(', ', $allowed);
            } else {
                $codes = [
                    UPLOAD_ERR_INI_SIZE   => 'Datei überschreitet upload_max_filesize (aktuell ' . ini_get('upload_max_filesize') . ').',
                    UPLOAD_ERR_FORM_SIZE  => 'Datei überschreitet MAX_FILE_SIZE des Formulars.',
                    UPLOAD_ERR_PARTIAL    => 'Datei wurde nur teilweise übertragen.',
                    UPLOAD_ERR_NO_FILE    => 'Keine Datei ausgewählt.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Temporäres Verzeichnis fehlt.',
                    UPLOAD_ERR_CANT_WRITE => 'Schreiben auf Festplatte fehlgeschlagen.',
                    UPLOAD_ERR_EXTENSION  => 'Upload durch PHP-Extension abgebrochen.',
                ];
                $code  = $file['error'] ?? -1;
                $error = $codes[$code] ?? "Upload fehlgeschlagen (PHP-Fehlercode {$code}).";
            }
        }

        require __DIR__ . '/../../templates/pages/model_upload.php';
    }

    public function delete(string $id): void
    {
        $this->db->deleteModel($id);
        header('Location: /models');
        exit;
    }

    public function stream(string $bucket, string $key): void
    {
        $allowed = ['models', 'logos', 'output'];
        if (!in_array($bucket, $allowed)) {
            http_response_code(400);
            exit;
        }
        $this->storage->stream($bucket, $key);
    }
}
