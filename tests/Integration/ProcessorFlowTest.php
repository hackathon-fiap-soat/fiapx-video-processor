<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Config\ParameterResolver;
use App\Infrastructure\Storage\LocalWorkspace;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use Tests\Support\Contracts;
use Tests\Support\Fixtures;
use Tests\Support\LocalStack;
use Tests\TestCase;

/**
 * processor:consume de ponta a ponta: vídeo real no S3 do LocalStack, ffmpeg real, zip no S3 e respostas na
 * FIFO validadas contra os schemas do fiapx-docs. Usa bucket e filas temporários.
 */
final class ProcessorFlowTest extends TestCase
{
    private LocalStack $aws;

    private string $dir;

    private string $bucket;

    private string $commands;

    private string $replies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aws = new LocalStack($this->app->make(S3Client::class), $this->app->make(SqsClient::class));
        $this->dir = Fixtures::tempDir();
        $this->bucket = $this->aws->bucket();
        $this->commands = $this->aws->queue();
        $this->replies = $this->aws->queue(fifo: true);
        $this->app->instance(ParameterResolver::class, new ParameterResolver(fn () => throw new \LogicException('sem SSM'), 'local', [
            'processing_commands_queue_url' => [$this->commands, 'x'], 'saga_replies_queue_url' => [$this->replies, 'y'],
        ]));
        $this->app->instance(LocalWorkspace::class, new LocalWorkspace($this->dir.'/work'));
        config(['fiapx.processor.worker_id' => 'pod-de-teste']);
    }

    protected function tearDown(): void
    {
        $this->aws->cleanup();
        Fixtures::removeDir($this->dir);
        parent::tearDown();
    }

    private function send(array $envelope): void
    {
        $this->aws->sqs->sendMessage(['QueueUrl' => $this->commands, 'MessageBody' => json_encode($envelope)]);
    }

    private function consume(): void
    {
        $this->artisan('processor:consume', ['--once' => true, '--wait' => 1])->assertSuccessful();
    }

    /** @return list<array<string, mixed>> */
    private function replies(int $expected): array
    {
        $bodies = $this->aws->drain($this->replies, $expected);
        $schemas = ['ProcessingStarted' => 'processing-started', 'VideoProcessed' => 'video-processed',
            'VideoProcessingFailed' => 'video-processing-failed', 'ArtifactsCleaned' => 'artifacts-cleaned'];
        foreach ($bodies as $body) {
            self::assertSame([], Contracts::errors($body, $schemas[$body['type']].'.v1.schema.json'), $body['type']);
        }

        return $bodies;
    }

    public function test_processa_um_video_real_e_e_idempotente(): void
    {
        $video = Fixtures::video($this->dir.'/aula.mp4', 3);
        $this->aws->s3->putObject(['Bucket' => $this->bucket, 'Key' => 'uploads/'.Fixtures::VIDEO.'.mp4', 'SourceFile' => $video]);
        $this->send(Fixtures::processVideo(bucket: $this->bucket));

        $this->consume();

        $replies = $this->replies(2);
        self::assertSame(['ProcessingStarted', 'VideoProcessed'], array_column($replies, 'type'));
        self::assertSame('pod-de-teste', $replies[0]['payload']['worker_id']);
        self::assertSame(Fixtures::CORRELATION, $replies[1]['correlation_id']);
        $payload = $replies[1]['payload'];
        self::assertSame('results/'.Fixtures::VIDEO.'/frames.zip', $payload['zip_key']);
        self::assertSame(3, $payload['frame_count']);

        $this->aws->s3->getObject(['Bucket' => $this->bucket, 'Key' => $payload['zip_key'], 'SaveAs' => $this->dir.'/frames.zip']);
        self::assertSame($payload['zip_size_bytes'], filesize($this->dir.'/frames.zip'));
        $zip = new \ZipArchive;
        self::assertTrue($zip->open($this->dir.'/frames.zip'));
        self::assertSame(['frame_0001.png', 'frame_0002.png', 'frame_0003.png'], [$zip->getNameIndex(0), $zip->getNameIndex(1), $zip->getNameIndex(2)]);
        $zip->close();
        self::assertSame([], glob($this->dir.'/work/*'), '/work precisa ficar limpo');
        self::assertSame(0, (int) $this->aws->sqs->getQueueAttributes(['QueueUrl' => $this->commands, 'AttributeNames' => ['All']])['Attributes']['ApproximateNumberOfMessagesNotVisible']);

        // Mensagem repetida: responde VideoProcessed com os mesmos números, sem ProcessingStarted.
        $this->send(Fixtures::processVideo(bucket: $this->bucket));
        $this->consume();
        $again = $this->replies(1);
        self::assertSame(['VideoProcessed'], array_column($again, 'type'));
        self::assertSame([3, $payload['zip_size_bytes']], [$again[0]['payload']['frame_count'], $again[0]['payload']['zip_size_bytes']]);

        // Compensação: apaga results/{id}/.
        $this->send(Fixtures::cleanupArtifacts(bucket: $this->bucket));
        $this->consume();
        $cleaned = $this->replies(1);
        self::assertSame('ArtifactsCleaned', $cleaned[0]['type']);
        self::assertSame(1, $cleaned[0]['payload']['deleted_objects']);
        self::assertSame([], $this->aws->s3->listObjectsV2(['Bucket' => $this->bucket, 'Prefix' => 'results/'])['Contents'] ?? []);
    }

    public function test_video_corrompido_responde_ffmpeg_error_e_apaga_a_mensagem(): void
    {
        $this->aws->s3->putObject(['Bucket' => $this->bucket, 'Key' => 'uploads/'.Fixtures::VIDEO.'.mov', 'Body' => 'isto não é um vídeo']);
        $this->send(Fixtures::processVideo(bucket: $this->bucket, ext: 'mov'));

        $this->consume();

        $replies = $this->replies(2);
        self::assertSame(['ProcessingStarted', 'VideoProcessingFailed'], array_column($replies, 'type'));
        self::assertSame('FFMPEG_ERROR', $replies[1]['payload']['error_code']);
        self::assertSame(1, $replies[1]['payload']['attempt']);
        $attrs = $this->aws->sqs->getQueueAttributes(['QueueUrl' => $this->commands, 'AttributeNames' => ['All']])['Attributes'];
        self::assertSame(0, (int) $attrs['ApproximateNumberOfMessages'] + (int) $attrs['ApproximateNumberOfMessagesNotVisible']);
        self::assertSame([], $this->aws->s3->listObjectsV2(['Bucket' => $this->bucket, 'Prefix' => 'results/'])['Contents'] ?? []);
    }
}
