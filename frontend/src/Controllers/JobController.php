<?php

namespace App\Controllers;

use App\Services\DatabaseService;
use App\Services\StorageService;

class JobController
{
    public function __construct(
        private DatabaseService $db,
        private StorageService  $storage
    ) {}

    public function index(): void
    {
        $jobs = $this->db->listJobs();
        require __DIR__ . '/../../templates/pages/jobs.php';
    }

    public function show(string $id): void
    {
        $job = $this->db->getJob($id);
        require __DIR__ . '/../../templates/pages/job_detail.php';
    }

    public function create(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $models = $this->db->listModels();

            $preselected  = $_GET['model_id']    ?? '';
            $prelogoKey   = $_GET['logo_key']    ?? '';
            $templateData = null;
            $templateId   = $_GET['template_id'] ?? '';
            if ($templateId) {
                $t = $this->db->getTemplate($templateId);
                if ($t) {
                    $templateData = [
                        'model_id'       => $t['model_id'],
                        'model_color'    => $t['model_color'],
                        'layers'         => $t['layers']         ? json_decode($t['layers'],         true) : [],
                        'print_settings' => $t['print_settings'] ? json_decode($t['print_settings'], true) : [],
                    ];
                    $preselected = $t['model_id'] ?? $preselected;
                }
            }

            require __DIR__ . '/../../templates/pages/upload.php';
            return;
        }

        $modelId = $_POST['model_id'] ?? '';
        $model   = $this->db->getModel($modelId);
        if (!$model) {
            http_response_code(400);
            echo 'Kein gültiges 3D-Modell gewählt.';
            return;
        }

        $modelColor = $_POST['model_color'] ?? '#4a9eff';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $modelColor)) $modelColor = '#4a9eff';

        $plateIndex    = isset($model['plate_index']) && $model['plate_index'] !== null
            ? (int)$model['plate_index'] : null;
        $filterObjects = isset($model['filter_objects']) && $model['filter_objects'] !== null
            ? $model['filter_objects'] : null;

        // ── Multi-layer path ──────────────────────────────────────────────────
        $layersJson   = null;
        $firstLogoKey = null;
        $firstPlacement = null;

        $rawLayersConfig = trim($_POST['layers_config'] ?? '');
        if ($rawLayersConfig !== '' && $rawLayersConfig !== '[]') {
            $layersConfig = json_decode($rawLayersConfig, true);
            if (is_array($layersConfig)) {
                $layerRows = [];
                foreach ($layersConfig as $i => $lc) {
                    $fileIndex   = $lc['file_index'] ?? null;
                    $existingKey = trim($lc['existing_key'] ?? '');

                    if ($fileIndex !== null) {
                        $fk = "layer_file_{$fileIndex}";
                        if (empty($_FILES[$fk]['tmp_name'])) continue;
                        $layerKey = uniqid() . "_layer{$i}.png";
                        $this->storage->upload('logos', $layerKey, $_FILES[$fk]['tmp_name']);
                    } elseif ($existingKey !== '') {
                        $layerKey = $existingKey;
                    } else {
                        continue;
                    }

                    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $lc['color'] ?? '')
                        ? $lc['color'] : '#ffffff';

                    $layerRows[] = [
                        'key'       => $layerKey,
                        'color'     => $color,
                        'placement' => $lc['placement'] ?? null,
                    ];
                    if ($firstLogoKey === null) {
                        $firstLogoKey   = $layerKey;
                        $firstPlacement = isset($lc['placement']) ? json_encode($lc['placement']) : null;
                    }
                }
                if (!empty($layerRows)) $layersJson = json_encode($layerRows);
            }
        }

        // ── Legacy single-logo fallback ───────────────────────────────────────
        if ($layersJson === null) {
            $logoColor = $_POST['logo_color'] ?? '#ffffff';
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $logoColor)) $logoColor = '#ffffff';

            $existingLogoKey = trim($_POST['existing_logo_key'] ?? '');
            if ($existingLogoKey !== '') {
                $firstLogoKey = $existingLogoKey;
            } else {
                if (empty($_FILES['logo']['tmp_name'])) {
                    http_response_code(400);
                    echo 'Kein Logo hochgeladen.';
                    return;
                }
                $firstLogoKey = uniqid() . '_' . basename($_FILES['logo']['name']);
                $this->storage->upload('logos', $firstLogoKey, $_FILES['logo']['tmp_name']);
            }
            $firstPlacement = $_POST['placement'] ?? null;
        } else {
            $logoColor = '#ffffff';
        }

        if (!$firstLogoKey) {
            http_response_code(400);
            echo 'Kein Logo hochgeladen.';
            return;
        }

        // ── Print settings ────────────────────────────────────────────────────
        $supportMode  = in_array($_POST['support_mode'] ?? '', ['none','auto','everywhere'])
            ? $_POST['support_mode'] : 'auto';
        $supportAngle = max(20, min(70, (int)($_POST['support_angle'] ?? 45)));
        $brimWidth    = max(0,  min(20, (float)($_POST['brim_width']    ?? 5)));
        $layerHeight  = in_array($_POST['layer_height'] ?? '', ['0.10','0.15','0.20','0.25','0.30'])
            ? (float)$_POST['layer_height'] : 0.20;
        $printDirection = in_array($_POST['print_direction'] ?? '', ['none','flip_z','x_pos','x_neg','y_pos','y_neg'])
            ? $_POST['print_direction'] : 'none';

        $detailFix = isset($_POST['detail_fix']) && $_POST['detail_fix'] === '1';

        $printSettings = json_encode([
            'support_mode'    => $supportMode,
            'support_angle'   => $supportAngle,
            'brim_width'      => $brimWidth,
            'layer_height'    => $layerHeight,
            'print_direction' => $printDirection,
            'detail_fix'      => $detailFix,
        ]);

        $jobId = $this->db->createJob(
            $firstLogoKey,
            $model['storage_key'],
            $firstPlacement ?: null,
            $modelColor,
            $logoColor,
            $plateIndex,
            $filterObjects,
            $layersJson,
            $printSettings
        );

        header("Location: /jobs/{$jobId}");
    }
}
