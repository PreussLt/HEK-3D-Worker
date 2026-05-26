<?php

namespace App\Services;

use PDO;

class DatabaseService
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'],
            $config['port'],
            $config['dbname']
        );

        $this->pdo = new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function getPdo(): PDO { return $this->pdo; }

    // ── Jobs ──────────────────────────────────────────────────────────────────

    public function createJob(
        string  $logoKey,
        string  $modelKey,
        ?string $placement     = null,
        string  $modelColor    = '#4a9eff',
        string  $logoColor     = '#ffffff',
        ?int    $plateIndex    = null,
        ?string $filterObjects = null,
        ?string $layers        = null,
        ?string $printSettings = null
    ): string {
        $stmt = $this->pdo->prepare(
            'INSERT INTO jobs
                 (logo_key, model_key, placement, model_color, logo_color,
                  plate_index, filter_objects, layers, print_settings)
             VALUES
                 (:logo, :model, :placement::jsonb, :model_color, :logo_color,
                  :plate_index, :filter_objects, :layers::jsonb, :print_settings::jsonb)
             RETURNING id'
        );
        $stmt->execute([
            'logo'           => $logoKey,
            'model'          => $modelKey,
            'placement'      => $placement,
            'model_color'    => $modelColor,
            'logo_color'     => $logoColor,
            'plate_index'    => $plateIndex,
            'filter_objects' => $filterObjects,
            'layers'         => $layers,
            'print_settings' => $printSettings,
        ]);
        return $stmt->fetchColumn();
    }

    public function getJob(string $id): array|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT j.*, m.name AS model_name, m.filename AS model_filename
             FROM jobs j
             LEFT JOIN models m ON m.storage_key = j.model_key
             WHERE j.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public function listJobs(): array
    {
        return $this->pdo->query('SELECT * FROM jobs ORDER BY created_at DESC')->fetchAll();
    }

    // ── Models ────────────────────────────────────────────────────────────────

    public function listModels(): array
    {
        return $this->pdo->query('SELECT * FROM models ORDER BY uploaded_at DESC')->fetchAll();
    }

    public function getModel(string $id): array|false
    {
        $stmt = $this->pdo->prepare('SELECT * FROM models WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public function createModel(
        string  $filename,
        string  $format,
        string  $storageKey,
        ?int    $plateIndex    = null,
        ?string $filterObjects = null,
        ?string $name          = null
    ): string {
        $stmt = $this->pdo->prepare(
            'INSERT INTO models (filename, format, storage_key, plate_index, filter_objects, name)
             VALUES (:filename, :format, :key, :plate_index, :filter_objects, :name)
             RETURNING id'
        );
        $stmt->execute([
            'filename'       => $filename,
            'format'         => $format,
            'key'            => $storageKey,
            'plate_index'    => $plateIndex,
            'filter_objects' => $filterObjects,
            'name'           => $name ?: null,
        ]);
        return $stmt->fetchColumn();
    }

    public function deleteModel(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM models WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    // ── Templates ─────────────────────────────────────────────────────────────

    public function createTemplate(
        string  $name,
        string  $modelId,
        string  $modelColor,
        ?string $layers        = null,
        ?string $printSettings = null
    ): string {
        $stmt = $this->pdo->prepare(
            'INSERT INTO job_templates (name, model_id, model_color, layers, print_settings)
             VALUES (:name, :model_id, :model_color, :layers::jsonb, :print_settings::jsonb)
             RETURNING id'
        );
        $stmt->execute([
            'name'           => $name,
            'model_id'       => $modelId ?: null,
            'model_color'    => $modelColor,
            'layers'         => $layers,
            'print_settings' => $printSettings,
        ]);
        return $stmt->fetchColumn();
    }

    public function listTemplates(): array
    {
        return $this->pdo->query(
            'SELECT t.*, m.filename AS model_filename, m.storage_key AS model_key,
                    m.format AS model_format, m.plate_index, m.filter_objects
             FROM job_templates t
             LEFT JOIN models m ON m.id = t.model_id
             ORDER BY t.created_at DESC'
        )->fetchAll();
    }

    public function getTemplate(string $id): array|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, m.filename AS model_filename, m.storage_key AS model_key,
                    m.format AS model_format, m.plate_index, m.filter_objects
             FROM job_templates t
             LEFT JOIN models m ON m.id = t.model_id
             WHERE t.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public function deleteTemplate(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM job_templates WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    // ── Magnet Jobs ───────────────────────────────────────────────────────────

    public function createMagnetJob(
        string  $modelKey,
        float   $diameter,
        float   $length,
        int     $nMagnets     = 4,
        ?string $faceSelection = null
    ): string {
        $stmt = $this->pdo->prepare(
            'INSERT INTO magnet_jobs
                 (model_key, magnet_diameter, magnet_length, n_magnets, face_selection)
             VALUES (:model_key, :diameter, :length, :n_magnets, :face_selection::jsonb)
             RETURNING id'
        );
        $stmt->execute([
            'model_key'      => $modelKey,
            'diameter'       => $diameter,
            'length'         => $length,
            'n_magnets'      => $nMagnets,
            'face_selection' => $faceSelection,
        ]);
        return $stmt->fetchColumn();
    }

    public function getMagnetJob(string $id): array|false
    {
        $stmt = $this->pdo->prepare('SELECT * FROM magnet_jobs WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public function listMagnetJobs(): array
    {
        return $this->pdo->query(
            'SELECT * FROM magnet_jobs ORDER BY created_at DESC'
        )->fetchAll();
    }

    // ── Convert Jobs ──────────────────────────────────────────────────────────

    public function createConvertJob(string $inputKey, string $filename): string
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO convert_jobs (input_key, filename)
             VALUES (:input_key, :filename)
             RETURNING id'
        );
        $stmt->execute(['input_key' => $inputKey, 'filename' => $filename]);
        return $stmt->fetchColumn();
    }

    public function getConvertJob(string $id): array|false
    {
        $stmt = $this->pdo->prepare('SELECT * FROM convert_jobs WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public function listConvertJobs(): array
    {
        return $this->pdo->query(
            'SELECT * FROM convert_jobs ORDER BY created_at DESC LIMIT 50'
        )->fetchAll();
    }
}
