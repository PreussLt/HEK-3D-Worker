<?php

namespace App\Controllers;

use App\Services\DatabaseService;
use App\Services\StorageService;

class ConvertController
{
    public function __construct(
        private DatabaseService $db,
        private StorageService  $storage
    ) {}

    public function index(): void
    {
        $jobs = $this->db->listConvertJobs();
        require __DIR__ . '/../../templates/pages/convert.php';
    }

    public function create(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $jobs = $this->db->listConvertJobs();
            require __DIR__ . '/../../templates/pages/convert.php';
            return;
        }

        $file = $_FILES['image'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo 'Kein gültiges Bild hochgeladen.';
            return;
        }

        $allowedTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp', 'image/bmp'];
        $mime         = mime_content_type($file['tmp_name']);
        if (!in_array($mime, $allowedTypes, true)) {
            http_response_code(400);
            echo 'Nur PNG, JPG, WEBP und BMP werden unterstützt.';
            return;
        }

        $origName = basename($file['name']);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $key      = 'logos/convert_' . bin2hex(random_bytes(8)) . '.' . $ext;

        $this->storage->upload('logos', $key, $file['tmp_name']);

        $jobId = $this->db->createConvertJob($key, $origName);
        header("Location: /convert/{$jobId}");
    }

    public function show(string $id): void
    {
        $job = $this->db->getConvertJob($id);
        require __DIR__ . '/../../templates/pages/convert_detail.php';
    }
}
