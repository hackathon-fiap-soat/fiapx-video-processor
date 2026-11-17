<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Messaging\MessageFactory;
use App\Domain\Processing\Entities\ProcessingJob;
use App\Domain\Processing\Exceptions\ProcessingFailed;
use App\Domain\Processing\Exceptions\TransientFailure;
use App\Domain\Processing\ValueObjects\ErrorCode;
use App\Domain\Processing\ValueObjects\FramesPerSecond;
use App\Domain\Processing\ValueObjects\SagaReference;
use App\Domain\Processing\ValueObjects\StorageLocation;
use App\Infrastructure\Config\ParameterResolver;
use App\Infrastructure\Messaging\SqsCommandConsumer;
use App\Infrastructure\Messaging\SqsReplyPublisher;
use App\Infrastructure\Storage\S3ObjectStorage;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use Psr\Log\NullLogger;
use Tests\Support\Fixtures;
use Tests\Support\LocalStack;
use Tests\TestCase;

/** Adaptadores do S3 e do SQS contra o LocalStack do fiapx-infra/local (bucket e filas temporários). */
final class AwsAdaptersTest extends TestCase
{
    private LocalStack $aws;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aws = new LocalStack($this->app->make(S3Client::class), $this->app->make(SqsClient::class));
        $this->dir = Fixtures::tempDir();
    }

    protected function tearDown(): void
    {
        $this->aws->cleanup();
        Fixtures::removeDir($this->dir);
        parent::tearDown();
    }

    public function test_s3_upload_head_download_e_limpeza(): void
    {
        $bucket = $this->aws->bucket();
        $storage = new S3ObjectStorage($this->aws->s3);
        $zip = new StorageLocation($bucket, 'results/'.Fixtures::VIDEO.'/frames.zip');
        self::assertNull($storage->head($zip));

        file_put_contents($this->dir.'/f.zip', str_repeat('z', 2048));
        $storage->upload($this->dir.'/f.zip', $zip, 'application/zip', ['frame-count' => '3', 'duration-ms' => '120']);
        $storage->upload($this->dir.'/f.zip', new StorageLocation($bucket, 'results/'.Fixtures::VIDEO.'/parcial.tmp'), 'application/octet-stream');
        $stored = $storage->head($zip);
        self::assertSame(2048, $stored?->sizeBytes);
        self::assertSame(3, $stored->intMetadata('frame-count'));

        $storage->download($zip, $this->dir.'/baixado.zip');
        self::assertSame(str_repeat('z', 2048), file_get_contents($this->dir.'/baixado.zip'));

        self::assertSame(2, $storage->deletePrefix(new StorageLocation($bucket, 'results/'.Fixtures::VIDEO.'/')));
        self::assertSame(0, $storage->deletePrefix(new StorageLocation($bucket, 'results/'.Fixtures::VIDEO.'/')));
        self::assertNull($storage->head($zip));
    }

    public function test_video_inexistente_e_falha_definitiva(): void
    {
        $storage = new S3ObjectStorage($this->aws->s3);
        try {
            $storage->download(new StorageLocation($this->aws->bucket(), 'uploads/'.Fixtures::VIDEO.'.mp4'), $this->dir.'/v.mp4');
            self::fail('deveria falhar');
        } catch (ProcessingFailed $e) {
            self::assertSame(ErrorCode::DownloadError, $e->error->code);
            self::assertFileDoesNotExist($this->dir.'/v.mp4');
        }
    }

    public function test_erros_do_s3_sao_transitorios(): void
    {
        $storage = new S3ObjectStorage($this->aws->s3);
        $semBucket = new StorageLocation('fiapx-nao-existe-'.bin2hex(random_bytes(3)), 'results/'.Fixtures::VIDEO.'/frames.zip');
        file_put_contents($this->dir.'/f.zip', 'z');
        foreach ([
            fn () => $storage->upload($this->dir.'/f.zip', $semBucket, 'application/zip'),
            fn () => $storage->deletePrefix(new StorageLocation($semBucket->bucket, 'results/'.Fixtures::VIDEO.'/')),
        ] as $i => $call) {
            try {
                $call();
                self::fail("chamada {$i} deveria falhar");
            } catch (TransientFailure $e) {
                self::assertContains($e->error->code, [ErrorCode::UploadError]);
            }
        }
    }

    public function test_sqs_recebe_estende_confirma_e_publica_na_fifo(): void
    {
        $commands = $this->aws->queue(visibility: 300);
        $replies = $this->aws->queue(fifo: true);
        $parameters = new ParameterResolver(fn () => throw new \LogicException('sem SSM'), 'local', [
            'processing_commands_queue_url' => [$commands, 'x'], 'saga_replies_queue_url' => [$replies, 'y'],
        ]);
        $consumer = new SqsCommandConsumer($this->aws->sqs, $parameters);
        self::assertNull($consumer->receive(0));

        $this->aws->sqs->sendMessage(['QueueUrl' => $commands, 'MessageBody' => '{"a":1}']);
        $m = $consumer->receive(1);
        self::assertNotNull($m);
        self::assertSame('{"a":1}', $m->body);
        self::assertSame(1, $m->receiveCount);
        // Visibility 0 devolve a mensagem na hora: o próximo recebimento conta 2.
        $consumer->extendVisibility($m, 0);
        $again = $consumer->receive(1);
        self::assertSame(2, $again?->receiveCount);
        $consumer->extendVisibility($again, 300);
        $consumer->delete($again);
        self::assertNull($consumer->receive(0));

        $publisher = new SqsReplyPublisher($this->aws->sqs, $parameters, $this->app->make(MessageFactory::class), new NullLogger);
        $saga = new SagaReference(Fixtures::SAGA, Fixtures::VIDEO, Fixtures::CORRELATION);
        $job = ProcessingJob::start($saga, new StorageLocation('b-1', 'uploads/'.Fixtures::VIDEO.'.mp4'),
            new StorageLocation('b-1', 'results/'.Fixtures::VIDEO.'/'), new FramesPerSecond(1), new \DateTimeImmutable);
        $publisher->processingStarted($job, 'pod-1');
        $publisher->artifactsCleaned($saga, 0);
        $bodies = $this->aws->drain($replies, 2);
        self::assertSame(['ProcessingStarted', 'ArtifactsCleaned'], array_column($bodies, 'type'));
    }
}
