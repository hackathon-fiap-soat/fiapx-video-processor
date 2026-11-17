<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Application\Processing\Ports\Workspace;
use App\Domain\Processing\Exceptions\ProcessingFailed;
use App\Domain\Processing\Exceptions\TransientFailure;
use App\Domain\Processing\Interfaces\FrameArchiver;
use App\Domain\Processing\Interfaces\FrameExtractor;
use App\Domain\Processing\Interfaces\ObjectStorage;
use App\Domain\Processing\Interfaces\ReplyPublisher;
use App\Domain\Processing\ValueObjects\ErrorCode;
use App\Domain\Processing\ValueObjects\FrameSet;
use App\Domain\Processing\ValueObjects\StoredObject;
use App\Infrastructure\Messaging\CommandQueue;
use App\Infrastructure\Storage\LocalWorkspace;
use App\Infrastructure\Support\LivenessFile;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\Fixtures;
use Tests\Support\InMemoryCommandQueue;
use Tests\TestCase;

/** O laço do processor:consume com fila em memória e portas falsas: quando confirma e quando devolve à fila. */
final class ConsumeCommandsCommandTest extends TestCase
{
    private InMemoryCommandQueue $queue;

    private ObjectStorage&MockInterface $storage;

    private ReplyPublisher&MockInterface $replies;

    private FrameExtractor&MockInterface $extractor;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = Fixtures::tempDir();
        $this->queue = new InMemoryCommandQueue;
        $this->storage = Mockery::mock(ObjectStorage::class);
        $this->replies = Mockery::mock(ReplyPublisher::class);
        $this->extractor = Mockery::mock(FrameExtractor::class);
        $archiver = Mockery::mock(FrameArchiver::class);
        $archiver->allows('archive')->andReturn(100);
        $this->app->instance(CommandQueue::class, $this->queue);
        $this->app->instance(ObjectStorage::class, $this->storage);
        $this->app->instance(ReplyPublisher::class, $this->replies);
        $this->app->instance(FrameExtractor::class, $this->extractor);
        $this->app->instance(FrameArchiver::class, $archiver);
        $workspace = new LocalWorkspace($this->dir.'/work');
        $this->app->instance(LocalWorkspace::class, $workspace);
        $this->app->instance(Workspace::class, $workspace);
        $this->app->instance(LivenessFile::class, new LivenessFile($this->dir.'/alive'));
    }

    protected function tearDown(): void
    {
        Fixtures::removeDir($this->dir);
        parent::tearDown();
    }

    private function consume(): void
    {
        $this->artisan('processor:consume', ['--once' => true, '--wait' => 0])->assertSuccessful();
    }

    public function test_sucesso_confirma_e_limpa_o_work(): void
    {
        $this->queue->push((string) json_encode(Fixtures::processVideo()));
        $this->queue->push((string) json_encode(Fixtures::cleanupArtifacts()));
        $this->storage->expects('head')->andReturnNull();
        $this->storage->expects('download');
        $this->extractor->expects('extract')->andReturn(new FrameSet('/x', ['/x/frame_0001.png']));
        $this->storage->expects('upload');
        $this->storage->expects('deletePrefix')->andReturn(1);
        $this->replies->expects('processingStarted');
        $this->replies->expects('videoProcessed');
        $this->replies->expects('artifactsCleaned');

        $this->consume();

        self::assertSame(['sqs-1', 'sqs-2'], $this->queue->deleted);
        self::assertSame([], glob($this->dir.'/work/*'));
        $this->artisan('processor:alive')->assertSuccessful();
    }

    public function test_falha_definitiva_responde_e_confirma(): void
    {
        $this->queue->push((string) json_encode(Fixtures::processVideo()));
        $this->storage->expects('head')->andReturnNull();
        $this->storage->expects('download');
        $this->extractor->expects('extract')->andThrow(ProcessingFailed::because(ErrorCode::FfmpegError, 'Invalid data'));
        $this->replies->expects('processingStarted');
        $this->replies->expects('videoProcessingFailed')->withArgs(fn ($job, int $attempt) => $job->error()->code === ErrorCode::FfmpegError && $attempt === 1);

        $this->consume();

        self::assertSame(['sqs-1'], $this->queue->deleted);
    }

    public function test_falha_transitoria_devolve_para_a_fila(): void
    {
        $this->queue->push((string) json_encode(Fixtures::processVideo()), receiveCount: 2);
        $this->queue->push((string) json_encode(Fixtures::cleanupArtifacts()), receiveCount: 3);
        $this->storage->expects('head')->andThrow(TransientFailure::because(ErrorCode::DownloadError, 'S3 fora do ar'));
        $this->storage->expects('deletePrefix')->andThrow(TransientFailure::because(ErrorCode::UploadError, 'S3 fora do ar'));
        $this->replies->expects('videoProcessingFailed')->never();

        $this->consume();

        self::assertSame([], $this->queue->deleted);
    }

    public function test_falha_transitoria_na_ultima_tentativa_vira_definitiva(): void
    {
        $this->queue->push((string) json_encode(Fixtures::processVideo()), receiveCount: 3);
        $this->storage->expects('head')->andReturnNull();
        $this->storage->expects('download')->andThrow(TransientFailure::because(ErrorCode::DownloadError, 'timeout'));
        $this->replies->expects('processingStarted');
        $this->replies->expects('videoProcessingFailed')->withArgs(fn ($job, int $attempt) => $job->error()->code === ErrorCode::DownloadError && $attempt === 3);

        $this->consume();

        self::assertSame(['sqs-1'], $this->queue->deleted);
    }

    public function test_se_a_falha_nao_for_publicada_a_mensagem_volta(): void
    {
        $this->queue->push((string) json_encode(Fixtures::processVideo()));
        $this->storage->expects('head')->andReturnNull();
        $this->storage->expects('download')->andThrow(ProcessingFailed::because(ErrorCode::DownloadError, 'não existe'));
        $this->replies->expects('processingStarted');
        $this->replies->expects('videoProcessingFailed')->andThrow(new \RuntimeException('SQS fora do ar'));

        $this->consume();

        self::assertSame([], $this->queue->deleted);
    }

    public function test_mensagem_invalida_e_erro_inesperado_nao_sao_confirmados(): void
    {
        $this->queue->push('{"type":"ProcessVideo"}');
        $this->queue->push((string) json_encode(Fixtures::processVideo()));
        $this->storage->expects('head')->andThrow(new \LogicException('bug'));

        $this->consume();

        self::assertSame([], $this->queue->deleted);
    }

    public function test_alive_falha_sem_sinal_de_vida(): void
    {
        $this->artisan('processor:alive')->assertFailed();
    }

    public function test_fila_vazia_sai_com_once(): void
    {
        $this->consume();
        self::assertSame([], $this->queue->deleted);
        self::assertInstanceOf(StoredObject::class, new StoredObject(1));
    }
}
