<?php

declare(strict_types=1);

namespace App\Domain\Processing\Entities;

use App\Domain\Processing\ValueObjects\FrameSet;
use App\Domain\Processing\ValueObjects\FramesPerSecond;
use App\Domain\Processing\ValueObjects\JobStatus;
use App\Domain\Processing\ValueObjects\ProcessingError;
use App\Domain\Processing\ValueObjects\SagaReference;
use App\Domain\Processing\ValueObjects\StorageLocation;

/**
 * Processamento de um vídeo por um worker (passo ProcessVideo da saga). Vive só durante o processamento: o
 * worker não tem banco; a idempotência vem da chave determinística results/{video_id}/frames.zip.
 *
 * PROCESSING → COMPLETED (finish) ou FAILED (fail).
 */
final class ProcessingJob
{
    public const ARCHIVE_NAME = 'frames.zip';

    private JobStatus $status = JobStatus::Processing;

    private ?\DateTimeImmutable $finishedAt = null;

    private ?int $frameCount = null;

    private ?int $zipSizeBytes = null;

    private ?int $durationMs = null;

    private ?ProcessingError $error = null;

    private function __construct(
        public readonly SagaReference $saga,
        public readonly StorageLocation $video,
        public readonly StorageLocation $outputPrefix,
        public readonly FramesPerSecond $fps,
        public readonly \DateTimeImmutable $startedAt,
    ) {
        if ($video->isPrefix() || ! $outputPrefix->isPrefix()) {
            throw new \InvalidArgumentException('O vídeo deve ser um objeto e a saída, um prefixo.');
        }
        if (! str_starts_with($video->key, "uploads/{$saga->videoId}.") || $outputPrefix->key !== "results/{$saga->videoId}/") {
            throw new \InvalidArgumentException('O vídeo e o prefixo de saída devem ser do mesmo video_id da saga.');
        }
    }

    public static function start(SagaReference $saga, StorageLocation $video, StorageLocation $outputPrefix, FramesPerSecond $fps, \DateTimeImmutable $at): self
    {
        return new self($saga, $video, $outputPrefix, $fps, $at);
    }

    /**
     * Zip já existente de uma execução anterior (mensagem repetida): o job nasce concluído com os números
     * guardados no objeto, sem reprocessar.
     */
    public static function alreadyProcessed(SagaReference $saga, StorageLocation $video, StorageLocation $outputPrefix, FramesPerSecond $fps,
        int $frameCount, int $zipSizeBytes, int $durationMs, \DateTimeImmutable $at): self
    {
        $job = new self($saga, $video, $outputPrefix, $fps, $at);
        $job->complete($frameCount, $zipSizeBytes, $durationMs, $at);

        return $job;
    }

    /** Chave do zip: results/{video_id}/frames.zip. */
    public function archive(): StorageLocation
    {
        return $this->outputPrefix->child(self::ARCHIVE_NAME);
    }

    public function finish(FrameSet $frames, int $zipSizeBytes, \DateTimeImmutable $at): void
    {
        $this->complete($frames->count(), $zipSizeBytes, $this->elapsedMs($at), $at);
    }

    public function fail(ProcessingError $error, \DateTimeImmutable $at): void
    {
        $this->assertProcessing();
        $this->status = JobStatus::Failed;
        $this->error = $error;
        $this->finishedAt = $at;
        $this->durationMs = $this->elapsedMs($at);
    }

    /** Milissegundos desde o início (nunca negativo). */
    public function elapsedMs(\DateTimeImmutable $at): int
    {
        $diff = (float) $at->format('U.u') - (float) $this->startedAt->format('U.u');

        return max(0, (int) round($diff * 1000));
    }

    public function status(): JobStatus
    {
        return $this->status;
    }

    public function frameCount(): ?int
    {
        return $this->frameCount;
    }

    public function zipSizeBytes(): ?int
    {
        return $this->zipSizeBytes;
    }

    public function durationMs(): ?int
    {
        return $this->durationMs;
    }

    public function error(): ?ProcessingError
    {
        return $this->error;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    private function complete(int $frameCount, int $zipSizeBytes, int $durationMs, \DateTimeImmutable $at): void
    {
        $this->assertProcessing();
        if ($frameCount < 1 || $zipSizeBytes < 1 || $durationMs < 0) {
            throw new \InvalidArgumentException('Zip concluído precisa de frames e tamanho positivos.');
        }
        $this->status = JobStatus::Completed;
        $this->frameCount = $frameCount;
        $this->zipSizeBytes = $zipSizeBytes;
        $this->durationMs = $durationMs;
        $this->finishedAt = $at;
    }

    private function assertProcessing(): void
    {
        if ($this->status !== JobStatus::Processing) {
            throw new \DomainException("O processamento já terminou ({$this->status->value}).");
        }
    }
}
