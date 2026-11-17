<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\Processing\Exceptions\ProcessingFailed;
use App\Domain\Processing\Exceptions\TransientFailure;
use App\Domain\Processing\Interfaces\ObjectStorage;
use App\Domain\Processing\ValueObjects\ErrorCode;
use App\Domain\Processing\ValueObjects\StorageLocation;
use App\Domain\Processing\ValueObjects\StoredObject;
use Aws\Exception\AwsException;
use Aws\S3\ObjectUploader;
use Aws\S3\S3Client;

/**
 * Bucket de vídeos no S3. Download em stream direto para o disco (SaveAs); upload pelo ObjectUploader, que usa
 * multipart acima de 16 MB. Erros do S3 viram TransientFailure (a mensagem volta para a fila), exceto o vídeo
 * inexistente, que é definitivo.
 */
final readonly class S3ObjectStorage implements ObjectStorage
{
    public function __construct(private S3Client $client) {}

    public function head(StorageLocation $object): ?StoredObject
    {
        try {
            $r = $this->client->headObject(['Bucket' => $object->bucket, 'Key' => $object->key]);
        } catch (AwsException $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            throw TransientFailure::because(ErrorCode::DownloadError, "HeadObject {$object}: {$this->describe($e)}", $e);
        }
        /** @var array<string, string> $metadata */
        $metadata = array_change_key_case(array_map('strval', (array) ($r['Metadata'] ?? [])));

        return new StoredObject((int) $r['ContentLength'], $metadata);
    }

    public function download(StorageLocation $object, string $localPath): void
    {
        try {
            $this->client->getObject(['Bucket' => $object->bucket, 'Key' => $object->key, 'SaveAs' => $localPath]);
        } catch (AwsException $e) {
            if (is_file($localPath)) {
                unlink($localPath);
            }
            if ($e->getStatusCode() === 404) {
                throw ProcessingFailed::because(ErrorCode::DownloadError, "O vídeo {$object} não existe.", $e);
            }
            throw TransientFailure::because(ErrorCode::DownloadError, "GetObject {$object}: {$this->describe($e)}", $e);
        }
    }

    public function upload(string $localPath, StorageLocation $object, string $contentType, array $metadata = []): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Não foi possível ler {$localPath}.");
        }
        try {
            (new ObjectUploader($this->client, $object->bucket, $object->key, $stream, 'private', [
                'params' => ['ContentType' => $contentType, 'Metadata' => $metadata],
            ]))->upload();
        } catch (\Throwable $e) {
            throw TransientFailure::because(ErrorCode::UploadError, "Upload {$object}: {$e->getMessage()}", $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function deletePrefix(StorageLocation $prefix): int
    {
        if (! $prefix->isPrefix()) {
            throw new \InvalidArgumentException("'{$prefix->key}' não é um prefixo.");
        }
        $deleted = 0;
        try {
            $pages = $this->client->getPaginator('ListObjectsV2', ['Bucket' => $prefix->bucket, 'Prefix' => $prefix->key]);
            foreach ($pages as $page) {
                $keys = array_map(fn (array $o) => ['Key' => (string) $o['Key']], $page['Contents'] ?? []);
                if ($keys === []) {
                    continue;
                }
                $r = $this->client->deleteObjects(['Bucket' => $prefix->bucket, 'Delete' => ['Objects' => $keys, 'Quiet' => true]]);
                if (! empty($r['Errors'])) {
                    throw new \RuntimeException('DeleteObjects devolveu erros: '.json_encode($r['Errors']));
                }
                $deleted += count($keys);
            }
        } catch (AwsException|\RuntimeException $e) {
            throw TransientFailure::because(ErrorCode::UploadError, "Limpeza de {$prefix}: {$e->getMessage()}", $e);
        }

        return $deleted;
    }

    private function describe(AwsException $e): string
    {
        return ($e->getAwsErrorCode() ?? 'erro').' (HTTP '.($e->getStatusCode() ?? '-').'): '.($e->getAwsErrorMessage() ?? $e->getMessage());
    }
}
