<?php

namespace App\Services;

use Aws\S3\S3Client;

class StorageService
{
    private S3Client $client;
    private array $buckets;

    public function __construct(array $config)
    {
        $this->client = new S3Client([
            'version'                 => 'latest',
            'region'                  => 'us-east-1',
            'endpoint'                => $config['endpoint'],
            'use_path_style_endpoint' => true,
            'credentials'             => [
                'key'    => $config['access_key'],
                'secret' => $config['secret_key'],
            ],
        ]);

        $this->buckets = $config['buckets'];
        $this->ensureBuckets();
    }

    private function ensureBuckets(): void
    {
        foreach ($this->buckets as $bucket) {
            try {
                $this->client->headBucket(['Bucket' => $bucket]);
            } catch (\Aws\Exception\AwsException $e) {
                $this->client->createBucket(['Bucket' => $bucket]);
            }
        }
    }

    public function upload(string $bucket, string $key, string $filePath): string
    {
        $this->client->putObject([
            'Bucket'     => $this->buckets[$bucket],
            'Key'        => $key,
            'SourceFile' => $filePath,
        ]);
        return $key;
    }

    public function download(string $bucket, string $key, string $targetPath): void
    {
        $this->client->getObject([
            'Bucket' => $this->buckets[$bucket],
            'Key'    => $key,
            'SaveAs' => $targetPath,
        ]);
    }

    public function stream(string $bucket, string $key): void
    {
        $result = $this->client->getObject([
            'Bucket' => $this->buckets[$bucket],
            'Key'    => $key,
        ]);

        $ext   = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        $mimes = [
            'stl'  => 'model/stl',
            'obj'  => 'text/plain',
            'ply'  => 'application/octet-stream',
            'glb'  => 'model/gltf-binary',
            'gltf' => 'model/gltf+json',
            '3mf'  => 'application/vnd.ms-package.3dmanufacturing-3dmodel+xml',
            'zip'  => 'application/zip',
            'png'  => 'image/png',
        ];

        header('Content-Type: '   . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . $result['ContentLength']);
        header('Cache-Control: private, max-age=3600');

        echo $result['Body'];
    }

    public function getUrl(string $bucket, string $key): string
    {
        return $this->client->getObjectUrl($this->buckets[$bucket], $key);
    }
}
