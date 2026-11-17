<?php

declare(strict_types=1);

namespace App\Domain\Processing\ValueObjects;

/**
 * Objeto (ou prefixo, se terminar em "/") num bucket. Convenção de chaves do fiapx-docs/contracts/README.md:
 * uploads/{video_id}.{ext}, results/{video_id}/ e results/{video_id}/frames.zip.
 */
final readonly class StorageLocation
{
    public const VIDEO_EXTENSIONS = ['mp4', 'avi', 'mov', 'mkv', 'wmv', 'flv', 'webm'];

    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(public string $bucket, public string $key)
    {
        if (preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket) !== 1) {
            throw new \InvalidArgumentException("Bucket inválido: '{$bucket}'.");
        }
        if ($key === '' || strlen($key) > 512 || str_starts_with($key, '/') || str_contains($key, '..')) {
            throw new \InvalidArgumentException("Chave inválida: '{$key}'.");
        }
    }

    /** Vídeo enviado: uploads/{video_id}.{ext}, com uma das extensões aceitas. */
    public static function uploadedVideo(string $bucket, string $key, string $videoId): self
    {
        $ext = implode('|', self::VIDEO_EXTENSIONS);
        if (preg_match('#^uploads/'.preg_quote($videoId, '#')."\\.({$ext})$#", $key) !== 1) {
            throw new \InvalidArgumentException("A chave do vídeo deve ser uploads/{$videoId}.<ext>: '{$key}'.");
        }

        return new self($bucket, $key);
    }

    /** Prefixo de resultados: results/{video_id}/ (o único que o worker grava e apaga). */
    public static function resultsPrefix(string $bucket, string $prefix, string $videoId): self
    {
        if ($prefix !== "results/{$videoId}/" || preg_match('#^results/'.self::UUID.'/$#', $prefix) !== 1) {
            throw new \InvalidArgumentException("O prefixo deve ser results/{$videoId}/: '{$prefix}'.");
        }

        return new self($bucket, $prefix);
    }

    public function isPrefix(): bool
    {
        return str_ends_with($this->key, '/');
    }

    /** Objeto dentro deste prefixo. */
    public function child(string $name): self
    {
        if (! $this->isPrefix()) {
            throw new \InvalidArgumentException("'{$this->key}' não é um prefixo.");
        }

        return new self($this->bucket, $this->key.$name);
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->key, PATHINFO_EXTENSION));
    }

    public function __toString(): string
    {
        return "s3://{$this->bucket}/{$this->key}";
    }
}
