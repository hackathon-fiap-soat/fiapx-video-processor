<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Messaging\IncomingCommand;
use App\Application\Processing\DTOs\ProcessOutcome;
use App\Application\Processing\DTOs\ProcessVideoCommand;
use App\Application\Processing\Ports\Heartbeat;
use App\Application\Processing\Ports\Workspace;
use App\Application\Processing\ProcessorSettings;
use App\Application\Processing\UseCases\ProcessVideoUseCase;
use App\Domain\Processing\Entities\ProcessingJob;
use App\Domain\Processing\Exceptions\ProcessingFailed;
use App\Domain\Processing\Exceptions\TransientFailure;
use App\Domain\Processing\Interfaces\FrameArchiver;
use App\Domain\Processing\Interfaces\FrameExtractor;
use App\Domain\Processing\Interfaces\ObjectStorage;
use App\Domain\Processing\Interfaces\ReplyPublisher;
use App\Domain\Processing\ValueObjects\ErrorCode;
use App\Domain\Processing\ValueObjects\FrameSet;
use App\Domain\Processing\ValueObjects\JobStatus;
use App\Domain\Processing\ValueObjects\ProcessingError;
use App\Domain\Processing\ValueObjects\StorageLocation;
use App\Domain\Processing\ValueObjects\StoredObject;
use App\Domain\Shared\Interfaces\Clock;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;

final class ProcessVideoUseCaseTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ObjectStorage&MockInterface $storage;

    private FrameExtractor&MockInterface $extractor;

    private FrameArchiver&MockInterface $archiver;

    private ReplyPublisher&MockInterface $replies;

    private Workspace&MockInterface $workspace;

    private Heartbeat&MockInterface $heartbeat;

    private ProcessVideoUseCase $useCase;

    private ProcessVideoCommand $command;

    protected function setUp(): void
    {
        $this->storage = Mockery::mock(ObjectStorage::class);
        $this->extractor = Mockery::mock(FrameExtractor::class);
        $this->archiver = Mockery::mock(FrameArchiver::class);
        $this->replies = Mockery::mock(ReplyPublisher::class);
        $this->workspace = Mockery::mock(Workspace::class);
        $this->heartbeat = Mockery::mock(Heartbeat::class);
        $this->heartbeat->allows('beat');
        $clock = new class implements Clock
        {
            private int $ms = 0;

            public function now(): \DateTimeImmutable
            {
                $this->ms += 250;

                return (new \DateTimeImmutable('2026-11-16T12:00:00Z'))->modify("+{$this->ms} milliseconds");
            }
        };
        $this->useCase = new ProcessVideoUseCase($this->storage, $this->extractor, $this->archiver, $this->replies, $this->workspace, $clock,
            new ProcessorSettings('pod-1', 3));
        $command = IncomingCommand::parse((string) json_encode(Fixtures::processVideo()));
        self::assertInstanceOf(ProcessVideoCommand::class, $command);
        $this->command = $command;
    }

    public function test_processa_sobe_o_zip_e_responde_na_ordem(): void
    {
        $zip = 'results/'.Fixtures::VIDEO.'/frames.zip';
        $this->storage->expects('head')->withArgs(fn (StorageLocation $l) => $l->key === $zip)->andReturnNull();
        $this->replies->expects('processingStarted')->withArgs(fn (ProcessingJob $j, string $w) => $w === 'pod-1')->ordered();
        $this->workspace->expects('create')->with(Fixtures::VIDEO)->andReturn('/work/x');
        $this->storage->expects('download')->withArgs(fn (StorageLocation $l, string $p) => $l->key === 'uploads/'.Fixtures::VIDEO.'.mp4' && $p === '/work/x/source.mp4');
        $frames = new FrameSet('/work/x/frames', ['/work/x/frames/frame_0001.png', '/work/x/frames/frame_0002.png', '/work/x/frames/frame_0003.png']);
        $this->extractor->expects('extract')->withArgs(fn ($v, $d, $fps, $tick) => $v === '/work/x/source.mp4' && $d === '/work/x/frames' && $fps->value === 1 && $tick instanceof \Closure)
            ->andReturnUsing(function ($v, $d, $fps, \Closure $tick) use ($frames) {
                $tick();

                return $frames;
            });
        $this->archiver->expects('archive')->with($frames, '/work/x/frames.zip')->andReturn(4096);
        $this->storage->expects('upload')->withArgs(fn (string $p, StorageLocation $l, string $type, array $meta) => $p === '/work/x/frames.zip'
            && $l->key === $zip && $type === 'application/zip' && $meta['frame-count'] === '3' && ctype_digit($meta['duration-ms']));
        $this->replies->expects('videoProcessed')->withArgs(fn (ProcessingJob $j) => $j->status() === JobStatus::Completed
            && $j->frameCount() === 3 && $j->zipSizeBytes() === 4096 && $j->durationMs() > 0)->ordered();
        $this->workspace->expects('remove')->with('/work/x');

        self::assertSame(ProcessOutcome::Processed, $this->useCase->execute($this->command, $this->heartbeat));
    }

    public function test_zip_existente_responde_sem_reprocessar(): void
    {
        $this->storage->expects('head')->andReturn(new StoredObject(777, ['frame-count' => '4', 'duration-ms' => '1500']));
        $this->replies->expects('videoProcessed')->withArgs(fn (ProcessingJob $j) => $j->frameCount() === 4 && $j->zipSizeBytes() === 777 && $j->durationMs() === 1500);
        $this->replies->expects('processingStarted')->never();
        $this->workspace->expects('create')->never();

        self::assertSame(ProcessOutcome::AlreadyProcessed, $this->useCase->execute($this->command, $this->heartbeat));
    }

    public function test_zip_existente_sem_metadados_e_reprocessado(): void
    {
        $this->storage->expects('head')->andReturn(new StoredObject(777));
        $this->replies->expects('processingStarted');
        $this->workspace->expects('create')->andReturn('/work/x');
        $this->storage->expects('download')->andThrow(TransientFailure::because(ErrorCode::DownloadError, 'timeout'));
        $this->workspace->expects('remove')->with('/work/x');

        $this->expectException(TransientFailure::class);
        $this->useCase->execute($this->command, $this->heartbeat);
    }

    public function test_falha_do_ffmpeg_sobe_e_limpa_o_workspace(): void
    {
        $this->storage->expects('head')->andReturnNull();
        $this->replies->expects('processingStarted');
        $this->workspace->expects('create')->andReturn('/work/x');
        $this->storage->expects('download');
        $this->extractor->expects('extract')->andThrow(ProcessingFailed::because(ErrorCode::FfmpegError, 'Invalid data'));
        $this->storage->expects('upload')->never();
        $this->replies->expects('videoProcessed')->never();
        $this->workspace->expects('remove')->with('/work/x');

        $this->expectException(ProcessingFailed::class);
        $this->useCase->execute($this->command, $this->heartbeat);
    }

    public function test_fail_publica_video_processing_failed_com_a_tentativa(): void
    {
        $this->replies->expects('videoProcessingFailed')->withArgs(fn (ProcessingJob $j, int $attempt) => $j->status() === JobStatus::Failed
            && $j->error()?->code === ErrorCode::NoFrames && $attempt === 2);

        $this->useCase->fail($this->command, new ProcessingError(ErrorCode::NoFrames, 'sem frames'), 2);
    }
}
