<?php

declare(strict_types=1);

namespace App\Application\Processing\DTOs;

use App\Domain\Processing\ValueObjects\FramesPerSecond;
use App\Domain\Processing\ValueObjects\SagaReference;
use App\Domain\Processing\ValueObjects\StorageLocation;

/** Comando ProcessVideo v1 já validado (fiapx-docs/contracts/process-video.v1.schema.json). */
final readonly class ProcessVideoCommand
{
    public function __construct(
        public string $messageId,
        public SagaReference $saga,
        public StorageLocation $video,
        public FramesPerSecond $fps,
        public StorageLocation $outputPrefix,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws \InvalidArgumentException campos ausentes ou fora da convenção de chaves
     */
    public static function fromPayload(string $messageId, SagaReference $saga, array $payload): self
    {
        $bucket = self::string($payload, 'bucket');

        return new self(
            $messageId,
            $saga,
            StorageLocation::uploadedVideo($bucket, self::string($payload, 'video_key'), $saga->videoId),
            new FramesPerSecond(is_int($payload['fps'] ?? null) ? $payload['fps'] : 0),
            StorageLocation::resultsPrefix($bucket, self::string($payload, 'output_prefix'), $saga->videoId),
        );
    }

    /** @param array<string, mixed> $payload */
    private static function string(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException("payload.{$field} ausente.");
        }

        return $value;
    }
}
