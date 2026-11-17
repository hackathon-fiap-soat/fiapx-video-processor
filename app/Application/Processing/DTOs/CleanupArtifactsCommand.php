<?php

declare(strict_types=1);

namespace App\Application\Processing\DTOs;

use App\Domain\Processing\ValueObjects\SagaReference;
use App\Domain\Processing\ValueObjects\StorageLocation;

/** Comando CleanupArtifacts v1 já validado (fiapx-docs/contracts/cleanup-artifacts.v1.schema.json). */
final readonly class CleanupArtifactsCommand
{
    public function __construct(public string $messageId, public SagaReference $saga, public StorageLocation $prefix) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws \InvalidArgumentException prefixo ausente ou diferente de results/{video_id}/
     */
    public static function fromPayload(string $messageId, SagaReference $saga, array $payload): self
    {
        $bucket = $payload['bucket'] ?? null;
        $prefix = $payload['prefix'] ?? null;
        if (! is_string($bucket) || ! is_string($prefix)) {
            throw new \InvalidArgumentException('payload.bucket e payload.prefix são obrigatórios.');
        }

        return new self($messageId, $saga, StorageLocation::resultsPrefix($bucket, $prefix, $saga->videoId));
    }
}
