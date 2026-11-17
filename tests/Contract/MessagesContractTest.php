<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Application\Messaging\IncomingCommand;
use App\Application\Messaging\MessageFactory;
use App\Application\Processing\DTOs\CleanupArtifactsCommand;
use App\Application\Processing\DTOs\ProcessVideoCommand;
use App\Domain\Processing\Entities\ProcessingJob;
use App\Domain\Processing\ValueObjects\ErrorCode;
use App\Domain\Processing\ValueObjects\FrameSet;
use App\Domain\Processing\ValueObjects\FramesPerSecond;
use App\Domain\Processing\ValueObjects\ProcessingError;
use App\Domain\Processing\ValueObjects\SagaReference;
use App\Domain\Processing\ValueObjects\StorageLocation;
use App\Infrastructure\Support\RamseyUuidV7Generator;
use App\Infrastructure\Support\SystemClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Contracts;

/**
 * Produtor estrito (as respostas do worker validam nos schemas do fiapx-docs, sem campos extras) e consumidor
 * tolerante (lê os exemplos de ProcessVideo e CleanupArtifacts, mesmo com campos a mais).
 */
final class MessagesContractTest extends TestCase
{
    private MessageFactory $factory;

    private ProcessVideoCommand $command;

    protected function setUp(): void
    {
        $this->factory = new MessageFactory(new RamseyUuidV7Generator, new SystemClock);
        $command = IncomingCommand::parse((string) json_encode(Contracts::example('process-video.v1.json')));
        self::assertInstanceOf(ProcessVideoCommand::class, $command);
        $this->command = $command;
    }

    private function job(): ProcessingJob
    {
        return ProcessingJob::start($this->command->saga, $this->command->video, $this->command->outputPrefix, $this->command->fps, new \DateTimeImmutable);
    }

    public function test_processing_started(): void
    {
        $msg = $this->factory->processingStarted($this->job(), 'fiapx-video-processor-7d9f-abcde');
        self::assertSame([], Contracts::errors($msg->envelope, 'processing-started.v1.schema.json'));
        // Mesmos ids do comando: a saga correlaciona pela saga_id e o New Relic pelo correlation_id.
        $exemplo = Contracts::example('process-video.v1.json');
        self::assertSame([$exemplo['saga_id'], $exemplo['video_id'], $exemplo['correlation_id']],
            [$msg->envelope['saga_id'], $msg->envelope['video_id'], $msg->envelope['correlation_id']]);
        self::assertSame([], Contracts::errors($this->factory->processingStarted($this->job(), '')->envelope, 'processing-started.v1.schema.json'));
    }

    public function test_video_processed(): void
    {
        $job = $this->job();
        $job->finish(new FrameSet('/w', ['/w/frame_0001.png', '/w/frame_0002.png']), 2048, new \DateTimeImmutable('+2 seconds'));
        $msg = $this->factory->videoProcessed($job);
        self::assertSame([], Contracts::errors($msg->envelope, 'video-processed.v1.schema.json'));
        self::assertSame('results/0192f1c4-7a2b-7c3d-8e4f-5a6b7c8d9e0f/frames.zip', $msg->envelope['payload']['zip_key']);
    }

    /** @return iterable<string, array{ErrorCode}> */
    public static function codigos(): iterable
    {
        foreach (ErrorCode::cases() as $code) {
            yield $code->value => [$code];
        }
    }

    #[DataProvider('codigos')]
    public function test_video_processing_failed(ErrorCode $code): void
    {
        $job = $this->job();
        $job->fail(new ProcessingError($code, str_repeat('erro longo ', 500)), new \DateTimeImmutable);
        $msg = $this->factory->videoProcessingFailed($job, 3);
        self::assertSame([], Contracts::errors($msg->envelope, 'video-processing-failed.v1.schema.json'));
        self::assertSame($code->value, $msg->envelope['payload']['error_code']);
    }

    public function test_artifacts_cleaned(): void
    {
        $saga = new SagaReference('0193a1b2-7d00-7e4f-8a5b-6c7d8e9f0a1d', '0192f1c4-7a2b-7c3d-8e4f-5a6b7c8d9e0f', '0193a1b2-7c3d-7e4f-8a5b-6c7d8e9f0a1e');
        foreach ([0, 1, 3600] as $n) {
            self::assertSame([], Contracts::errors($this->factory->artifactsCleaned($saga, $n)->envelope, 'artifacts-cleaned.v1.schema.json'));
        }
    }

    public function test_consome_os_exemplos_de_comando_com_campos_desconhecidos(): void
    {
        $pv = Contracts::example('process-video.v1.json');
        $pv['campo_futuro'] = 'ignorado';
        $pv['payload']['outro_campo'] = 1;
        $command = IncomingCommand::parse((string) json_encode($pv));
        self::assertInstanceOf(ProcessVideoCommand::class, $command);
        self::assertSame($pv['payload']['bucket'], $command->video->bucket);
        self::assertSame($pv['payload']['video_key'], $command->video->key);
        self::assertSame(1, $command->fps->value);

        $ca = Contracts::example('cleanup-artifacts.v1.json');
        $ca['payload']['outro_campo'] = true;
        $cleanup = IncomingCommand::parse((string) json_encode($ca));
        self::assertInstanceOf(CleanupArtifactsCommand::class, $cleanup);
        self::assertSame($ca['payload']['prefix'], $cleanup->prefix->key);
    }

    public function test_os_exemplos_de_resposta_do_fiapx_docs_tem_o_formato_que_o_worker_produz(): void
    {
        // Os exemplos do worker estão no fiapx-docs: garante que os campos dos dois lados batem.
        $job = ProcessingJob::alreadyProcessed(new SagaReference('0193a1b2-7d00-7e4f-8a5b-6c7d8e9f0a1d', '0192f1c4-7a2b-7c3d-8e4f-5a6b7c8d9e0f', '0193a1b2-7c3d-7e4f-8a5b-6c7d8e9f0a1e'),
            new StorageLocation('fiapx-prod-videos', 'uploads/0192f1c4-7a2b-7c3d-8e4f-5a6b7c8d9e0f.mp4'),
            new StorageLocation('fiapx-prod-videos', 'results/0192f1c4-7a2b-7c3d-8e4f-5a6b7c8d9e0f/'), new FramesPerSecond(1), 1, 1, 0, new \DateTimeImmutable);
        foreach (['processing-started.v1.json' => $this->factory->processingStarted($job, 'pod'), 'video-processed.v1.json' => $this->factory->videoProcessed($job)] as $file => $msg) {
            $exemplo = Contracts::example($file);
            self::assertSame(array_keys($exemplo), array_keys($msg->envelope), $file);
            self::assertEqualsCanonicalizing(array_keys($exemplo['payload']), array_keys($msg->envelope['payload']), $file);
        }
    }
}
