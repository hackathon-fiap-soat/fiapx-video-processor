<?php

declare(strict_types=1);

namespace App\Application\Processing\UseCases;

use App\Application\Processing\DTOs\ProcessOutcome;
use App\Application\Processing\DTOs\ProcessVideoCommand;
use App\Application\Processing\Ports\Heartbeat;
use App\Application\Processing\Ports\Workspace;
use App\Application\Processing\ProcessorSettings;
use App\Domain\Processing\Entities\ProcessingJob;
use App\Domain\Processing\Exceptions\ProcessingFailed;
use App\Domain\Processing\Exceptions\TransientFailure;
use App\Domain\Processing\Interfaces\FrameArchiver;
use App\Domain\Processing\Interfaces\FrameExtractor;
use App\Domain\Processing\Interfaces\ObjectStorage;
use App\Domain\Processing\Interfaces\ReplyPublisher;
use App\Domain\Processing\ValueObjects\ProcessingError;
use App\Domain\Shared\Interfaces\Clock;

/**
 * Passo ProcessVideo da saga (spec §8.5, ADR-009): ProcessingStarted → baixa o vídeo → ffmpeg a 1 fps →
 * zip → sobe results/{video_id}/frames.zip → VideoProcessed.
 *
 * Idempotente: se o zip já existe (mensagem repetida), responde VideoProcessed sem reprocessar. As falhas
 * sobem como exceção (ProcessingFailed definitiva, TransientFailure transitória) para o consumidor decidir
 * entre apagar a mensagem e deixá-la voltar para a fila; ele usa fail() para responder VideoProcessingFailed.
 */
final readonly class ProcessVideoUseCase
{
    /** Metadados gravados no zip, lidos de volta na execução idempotente. */
    public const META_FRAME_COUNT = 'frame-count';

    public const META_DURATION_MS = 'duration-ms';

    public function __construct(
        private ObjectStorage $storage,
        private FrameExtractor $extractor,
        private FrameArchiver $archiver,
        private ReplyPublisher $replies,
        private Workspace $workspace,
        private Clock $clock,
        private ProcessorSettings $settings,
    ) {}

    /**
     * @throws ProcessingFailed falha definitiva (vídeo corrompido, sem frames, inexistente)
     * @throws TransientFailure falha de S3 ou rede
     */
    public function execute(ProcessVideoCommand $command, Heartbeat $heartbeat): ProcessOutcome
    {
        $job = ProcessingJob::start($command->saga, $command->video, $command->outputPrefix, $command->fps, $this->clock->now());

        $existing = $this->storage->head($job->archive());
        $frameCount = $existing?->intMetadata(self::META_FRAME_COUNT);
        if ($existing !== null && $frameCount !== null && $frameCount > 0 && $existing->sizeBytes > 0) {
            $done = ProcessingJob::alreadyProcessed($command->saga, $command->video, $command->outputPrefix, $command->fps,
                $frameCount, $existing->sizeBytes, $existing->intMetadata(self::META_DURATION_MS) ?? 0, $this->clock->now());
            $this->replies->videoProcessed($done);

            return ProcessOutcome::AlreadyProcessed;
        }

        $this->replies->processingStarted($job, $this->settings->workerId);
        $dir = $this->workspace->create($command->saga->videoId);
        try {
            $video = "{$dir}/source.{$command->video->extension()}";
            $this->storage->download($command->video, $video);
            $heartbeat->beat();

            $frames = $this->extractor->extract($video, "{$dir}/frames", $command->fps, $heartbeat->beat(...));
            $zip = "{$dir}/".ProcessingJob::ARCHIVE_NAME;
            $size = $this->archiver->archive($frames, $zip);
            $heartbeat->beat();

            $this->storage->upload($zip, $job->archive(), 'application/zip', [
                self::META_FRAME_COUNT => (string) $frames->count(),
                self::META_DURATION_MS => (string) $job->elapsedMs($this->clock->now()),
            ]);
            $job->finish($frames, $size, $this->clock->now());
            $this->replies->videoProcessed($job);
        } finally {
            $this->workspace->remove($dir);
        }

        return ProcessOutcome::Processed;
    }

    /** Responde VideoProcessingFailed (falha definitiva, ou transitória na última tentativa). */
    public function fail(ProcessVideoCommand $command, ProcessingError $error, int $attempt): void
    {
        $job = ProcessingJob::start($command->saga, $command->video, $command->outputPrefix, $command->fps, $this->clock->now());
        $job->fail($error, $this->clock->now());
        $this->replies->videoProcessingFailed($job, $attempt);
    }
}
