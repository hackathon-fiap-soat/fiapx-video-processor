<?php

declare(strict_types=1);

namespace App\Application\Processing\DTOs;

/**
 * Atributos do evento VideoProcessed (fiapx-docs/07-observabilidade/observabilidade.md). Na execução
 * idempotente ($reused), duration_ms é o do processamento original (metadado do zip) e ffmpeg_ms e
 * video_size_bytes são 0, porque o vídeo não foi baixado nem processado de novo.
 */
final readonly class VideoProcessedMetrics
{
    public function __construct(
        public string $videoId,
        public string $sagaId,
        public string $correlationId,
        public string $workerId,
        public int $durationMs,
        public int $ffmpegMs,
        public int $frameCount,
        public int $videoSizeBytes,
        public int $zipSizeBytes,
        public bool $reused,
    ) {}

    /** @return array<string, string|int|bool> nomes dos atributos como no documento de observabilidade */
    public function toAttributes(): array
    {
        return [
            'video_id' => $this->videoId,
            'saga_id' => $this->sagaId,
            'correlation_id' => $this->correlationId,
            'worker_id' => $this->workerId,
            'duration_ms' => $this->durationMs,
            'ffmpeg_ms' => $this->ffmpegMs,
            'frame_count' => $this->frameCount,
            'video_size_bytes' => $this->videoSizeBytes,
            'zip_size_bytes' => $this->zipSizeBytes,
            'reused' => $this->reused,
        ];
    }
}
