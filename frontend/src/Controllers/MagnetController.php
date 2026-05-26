<?php

namespace App\Controllers;

use App\Services\DatabaseService;
use App\Services\StorageService;

class MagnetController
{
    public function __construct(
        private DatabaseService $db,
        private StorageService  $storage
    ) {}

    public function index(): void
    {
        $jobs = $this->db->listMagnetJobs();
        require __DIR__ . '/../../templates/pages/magnet_jobs.php';
    }

    public function create(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $models = $this->db->listModels();
            require __DIR__ . '/../../templates/pages/magnet.php';
            return;
        }

        $modelId  = $_POST['model_id'] ?? '';
        $diameter = (float) ($_POST['magnet_diameter'] ?? 6.0);
        $length   = (float) ($_POST['magnet_length']   ?? 2.0);
        $nMagnets = (int)   ($_POST['n_magnets']       ?? 4);
        $faceSel  = $_POST['face_selection'] ?? null;

        $diameter = max(1.0, min(50.0, $diameter));
        $length   = max(0.5, min(50.0, $length));
        $nMagnets = max(1,   min(8,    $nMagnets));

        // Validate face_selection JSON if provided
        if ($faceSel !== null && $faceSel !== '') {
            $decoded = json_decode($faceSel, true);
            $faceSel = ($decoded !== null) ? json_encode($decoded) : null;
        } else {
            $faceSel = null;
        }

        $model = $this->db->getModel($modelId);
        if (!$model) {
            http_response_code(400);
            echo 'Kein gültiges 3D-Modell gewählt.';
            return;
        }

        $jobId = $this->db->createMagnetJob(
            $model['storage_key'], $diameter, $length, $nMagnets, $faceSel
        );
        header("Location: /magnet/{$jobId}");
    }

    public function show(string $id): void
    {
        $job = $this->db->getMagnetJob($id);
        require __DIR__ . '/../../templates/pages/magnet_detail.php';
    }
}
