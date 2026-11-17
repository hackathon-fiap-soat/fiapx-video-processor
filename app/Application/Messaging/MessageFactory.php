<?php

declare(strict_types=1);

namespace App\Application\Messaging;

use App\Domain\Processing\Entities\ProcessingJob;
use App\Domain\Processing\ValueObjects\JobStatus;
use App\Domain\Processing\ValueObjects\SagaReference;
use App\Domain\Shared\Interfaces\Clock;
use App\Domain\Shared\Interfaces\IdGenerator;

/**
 * Monta as respostas do worker no formato dos contratos v1 (produtor estrito: só os campos do schema).
 * saga_id, video_id e correlation_id são sempre os do comando recebido.
 */
final readonly class MessageFactory
{
    public function __construct(private IdGenerator $ids, private Clock $clock) {}

    public function processingStarted(ProcessingJob $job, string $workerId): OutgoingMessage
    {
        return $this->envelope('ProcessingStarted', $job->saga, [
            'started_at' => self::utc($job->startedAt),
            'worker_id' => mb_substr($workerId !== '' ? $workerId : 'fiapx-video-processor', 0, 255),
        ]);
    }

    public function videoProcessed(ProcessingJob $job): OutgoingMessage
    {
        if ($job->status() !== JobStatus::Completed) {
            throw new \DomainException('VideoProcessed exige um processamento concluído.');
        }

        return $this->envelope('VideoProcessed', $job->saga, [
            'zip_key' => $job->archive()->key,
            'frame_count' => (int) $job->frameCount(),
            'zip_size_bytes' => (int) $job->zipSizeBytes(),
            'duration_ms' => (int) $job->durationMs(),
        ]);
    }

    public function videoProcessingFailed(ProcessingJob $job, int $attempt): OutgoingMessage
    {
        $error = $job->error();
        if ($job->status() !== JobStatus::Failed || $error === null) {
            throw new \DomainException('VideoProcessingFailed exige um processamento com falha.');
        }

        return $this->envelope('VideoProcessingFailed', $job->saga, [
            'error_code' => $error->code->value,
            'error_message' => $error->message,
            'attempt' => max(1, $attempt),
        ]);
    }

    public function artifactsCleaned(SagaReference $saga, int $deletedObjects): OutgoingMessage
    {
        return $this->envelope('ArtifactsCleaned', $saga, ['deleted_objects' => max(0, $deletedObjects)]);
    }

    /** @param array<string, mixed> $payload */
    private function envelope(string $type, SagaReference $saga, array $payload): OutgoingMessage
    {
        return new OutgoingMessage([
            'message_id' => $this->ids->next(),
            'type' => $type,
            'version' => 1,
            'saga_id' => $saga->sagaId,
            'video_id' => $saga->videoId,
            'correlation_id' => $saga->correlationId,
            'occurred_at' => self::utc($this->clock->now()),
            'payload' => $payload,
        ]);
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
