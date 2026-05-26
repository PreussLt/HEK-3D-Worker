<?php

namespace App\Controllers;

use App\Services\DatabaseService;
use App\Services\StorageService;

class TemplateController
{
    public function __construct(
        private DatabaseService $db,
        private StorageService  $storage
    ) {}

    public function index(): void
    {
        $templates = $this->db->listTemplates();
        require __DIR__ . '/../../templates/pages/templates.php';
    }

    public function create(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return;
        }

        $name    = trim($_POST['template_name'] ?? '');
        $modelId = trim($_POST['model_id']      ?? '');
        if ($name === '' || $modelId === '') {
            http_response_code(400);
            echo 'Name und Modell sind erforderlich.';
            return;
        }

        $modelColor = $_POST['model_color'] ?? '#4a9eff';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $modelColor)) $modelColor = '#4a9eff';

        // Parse and persist layers
        $rawLayers = trim($_POST['template_layers'] ?? '');
        $layersJson = null;
        if ($rawLayers !== '' && $rawLayers !== '[]') {
            $parsed = json_decode($rawLayers, true);
            if (is_array($parsed)) {
                $rows = [];
                foreach ($parsed as $i => $lc) {
                    // Logo layers may carry a new file or an existing key
                    $fileIndex   = $lc['file_index'] ?? null;
                    $existingKey = trim($lc['existing_key'] ?? '');

                    if ($lc['type'] === 'logo') {
                        if ($fileIndex !== null) {
                            $fk = "template_file_{$fileIndex}";
                            if (empty($_FILES[$fk]['tmp_name'])) continue;
                            $layerKey = uniqid() . "_tmpl{$i}.png";
                            $this->storage->upload('logos', $layerKey, $_FILES[$fk]['tmp_name']);
                            $lc['key'] = $layerKey;
                        } elseif ($existingKey !== '') {
                            $lc['key'] = $existingKey;
                        } else {
                            continue;  // logo with no file — skip
                        }
                    }
                    unset($lc['file_index'], $lc['existing_key'], $lc['blob_url']);
                    $rows[] = $lc;
                }
                if (!empty($rows)) $layersJson = json_encode($rows);
            }
        }

        // Print settings
        $supportMode  = in_array($_POST['support_mode'] ?? '', ['none','auto','everywhere'])
            ? $_POST['support_mode'] : 'auto';
        $supportAngle = max(20, min(70, (int)($_POST['support_angle'] ?? 45)));
        $brimWidth    = max(0, min(20, (float)($_POST['brim_width'] ?? 5)));
        $layerHeight  = in_array($_POST['layer_height'] ?? '', ['0.10','0.15','0.20','0.25','0.30'])
            ? (float)$_POST['layer_height'] : 0.20;

        $printSettings = json_encode([
            'support_mode'  => $supportMode,
            'support_angle' => $supportAngle,
            'brim_width'    => $brimWidth,
            'layer_height'  => $layerHeight,
        ]);

        $this->db->createTemplate($name, $modelId, $modelColor, $layersJson, $printSettings);
        header('Location: /templates');
    }

    public function delete(string $id): void
    {
        $this->db->deleteTemplate($id);
        header('Location: /templates');
    }
}
