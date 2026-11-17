<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Processing\Entities\ProcessingJob;
use App\Domain\Processing\ValueObjects\ErrorCode;
use App\Domain\Processing\ValueObjects\FrameSet;
use App\Domain\Processing\ValueObjects\FramesPerSecond;
use App\Domain\Processing\ValueObjects\JobStatus;
use App\Domain\Processing\ValueObjects\ProcessingError;
use App\Domain\Processing\ValueObjects\SagaReference;
use App\Domain\Processing\ValueObjects\StorageLocation;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;

final class ProcessingJobTest extends TestCase
{
    private function job(string $videoKey = 'uploads/'.Fixtures::VIDEO.'.mp4', string $prefix = 'results/'.Fixtures::VIDEO.'/'): ProcessingJob
    {
        return ProcessingJob::start(
            new SagaReference(Fixtures::SAGA, Fixtures::VIDEO, Fixtures::CORRELATION),
            new StorageLocation(Fixtures::BUCKET, $videoKey),
            new StorageLocation(Fixtures::BUCKET, $prefix),
            new FramesPerSecond(1),
            new \DateTimeImmutable('2026-11-16T12:00:00.000000Z'),
        );
    }

    public function test_conclui_com_frames_tamanho_e_duracao(): void
    {
        $job = $this->job();
        self::assertSame(JobStatus::Processing, $job->status());
        self::assertSame('results/'.Fixtures::VIDEO.'/frames.zip', $job->archive()->key);

        $job->finish(new FrameSet('/w', ['/w/a.png', '/w/b.png', '/w/c.png']), 1234, new \DateTimeImmutable('2026-11-16T12:00:02.500000Z'));

        self::assertSame(JobStatus::Completed, $job->status());
        self::assertSame(3, $job->frameCount());
        self::assertSame(1234, $job->zipSizeBytes());
        self::assertSame(2500, $job->durationMs());
        self::assertNotNull($job->finishedAt());
        self::assertNull($job->error());
    }

    public function test_falha_guarda_o_erro(): void
    {
        $job = $this->job();
        $job->fail(new ProcessingError(ErrorCode::FfmpegError, 'moov atom not found'), new \DateTimeImmutable('2026-11-16T12:00:01Z'));
        self::assertSame(JobStatus::Failed, $job->status());
        self::assertSame(ErrorCode::FfmpegError, $job->error()?->code);
        self::assertSame(1000, $job->durationMs());
    }

    public function test_nao_muda_depois_de_terminar(): void
    {
        $job = $this->job();
        $job->fail(new ProcessingError(ErrorCode::NoFrames, ''), new \DateTimeImmutable);
        $this->expectException(\DomainException::class);
        $job->finish(new FrameSet('/w', ['/w/a.png']), 10, new \DateTimeImmutable);
    }

    public function test_ja_processado_nasce_concluido(): void
    {
        $saga = new SagaReference(Fixtures::SAGA, Fixtures::VIDEO, Fixtures::CORRELATION);
        $job = ProcessingJob::alreadyProcessed($saga, new StorageLocation('b-1', 'uploads/'.Fixtures::VIDEO.'.mov'),
            new StorageLocation('b-1', 'results/'.Fixtures::VIDEO.'/'), new FramesPerSecond(1), 5, 999, 42, new \DateTimeImmutable);
        self::assertSame(JobStatus::Completed, $job->status());
        self::assertSame([5, 999, 42], [$job->frameCount(), $job->zipSizeBytes(), $job->durationMs()]);
    }

    public function test_zip_concluido_precisa_de_tamanho_positivo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->job()->finish(new FrameSet('/w', ['/w/a.png']), 0, new \DateTimeImmutable);
    }

    public function test_video_de_outro_id_e_rejeitado(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->job('uploads/0192f1c4-0000-7000-8000-000000000000.mp4');
    }

    public function test_saida_precisa_ser_prefixo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->job(prefix: 'results/'.Fixtures::VIDEO.'/frames.zip');
    }

    public function test_duracao_nunca_negativa(): void
    {
        self::assertSame(0, $this->job()->elapsedMs(new \DateTimeImmutable('2026-11-16T11:00:00Z')));
    }
}
