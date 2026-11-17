<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Messaging\IncomingCommand;
use App\Application\Messaging\MessageFactory;
use App\Application\Processing\DTOs\CleanupArtifactsCommand;
use App\Application\Processing\DTOs\ProcessVideoCommand;
use App\Application\Processing\UseCases\CleanupArtifactsUseCase;
use App\Domain\Processing\Entities\ProcessingJob;
use App\Domain\Processing\Interfaces\ObjectStorage;
use App\Domain\Processing\Interfaces\ReplyPublisher;
use App\Domain\Processing\ValueObjects\FramesPerSecond;
use App\Domain\Processing\ValueObjects\SagaReference;
use App\Domain\Processing\ValueObjects\StorageLocation;
use App\Infrastructure\Support\RamseyUuidV7Generator;
use App\Infrastructure\Support\SystemClock;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;

final class CleanupAndParsingTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_cleanup_apaga_o_prefixo_e_responde(): void
    {
        $storage = Mockery::mock(ObjectStorage::class);
        $replies = Mockery::mock(ReplyPublisher::class);
        $command = IncomingCommand::parse((string) json_encode(Fixtures::cleanupArtifacts()));
        self::assertInstanceOf(CleanupArtifactsCommand::class, $command);
        $storage->expects('deletePrefix')->withArgs(fn (StorageLocation $p) => $p->key === 'results/'.Fixtures::VIDEO.'/')->andReturn(2);
        $replies->expects('artifactsCleaned')->withArgs(fn (SagaReference $s, int $n) => $s->sagaId === Fixtures::SAGA && $n === 2);

        self::assertSame(2, (new CleanupArtifactsUseCase($storage, $replies))->execute($command));
    }

    public function test_parse_tolerante_ignora_campos_desconhecidos(): void
    {
        $env = Fixtures::processVideo(ext: 'webm');
        $env['campo_futuro'] = true;
        $env['payload']['outro'] = 1;
        $command = IncomingCommand::parse((string) json_encode($env));
        self::assertInstanceOf(ProcessVideoCommand::class, $command);
        self::assertSame('webm', $command->video->extension());
        self::assertSame(Fixtures::CORRELATION, $command->saga->correlationId);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidas(): iterable
    {
        $mod = function (callable $f, ?array $base = null): string {
            $e = $base ?? Fixtures::processVideo();
            $f($e);

            return (string) json_encode($e);
        };
        yield 'não é json' => ['nada'];
        yield 'versão 2' => [$mod(function (&$e) {
            $e['version'] = 2;
        })];
        yield 'tipo desconhecido' => [$mod(function (&$e) {
            $e['type'] = 'VideoProcessed';
        })];
        yield 'sem payload' => [$mod(function (&$e) {
            unset($e['payload']);
        })];
        yield 'saga_id inválido' => [$mod(function (&$e) {
            $e['saga_id'] = 'x';
        })];
        yield 'sem bucket' => [$mod(function (&$e) {
            unset($e['payload']['bucket']);
        })];
        yield 'fps 0' => [$mod(function (&$e) {
            $e['payload']['fps'] = 0;
        })];
        yield 'saída de outro vídeo' => [$mod(function (&$e) {
            $e['payload']['output_prefix'] = 'results/0192f1c4-0000-7000-8000-000000000000/';
        })];
        yield 'cleanup fora de results' => [$mod(function (&$e) {
            $e['payload']['prefix'] = 'uploads/';
        }, Fixtures::cleanupArtifacts())];
        yield 'cleanup sem prefix' => [$mod(function (&$e) {
            unset($e['payload']['prefix']);
        }, Fixtures::cleanupArtifacts())];
    }

    #[DataProvider('invalidas')]
    public function test_parse_rejeita_mensagens_invalidas(string $body): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IncomingCommand::parse($body);
    }

    public function test_message_factory_recusa_estado_errado(): void
    {
        $f = new MessageFactory(new RamseyUuidV7Generator, new SystemClock);
        $job = ProcessingJob::start(new SagaReference(Fixtures::SAGA, Fixtures::VIDEO, Fixtures::CORRELATION),
            new StorageLocation('b-1', 'uploads/'.Fixtures::VIDEO.'.mp4'), new StorageLocation('b-1', 'results/'.Fixtures::VIDEO.'/'),
            new FramesPerSecond(1), new \DateTimeImmutable);
        try {
            $f->videoProcessed($job);
            self::fail('VideoProcessed com job em andamento');
        } catch (\DomainException) {
        }
        $this->expectException(\DomainException::class);
        $f->videoProcessingFailed($job, 1);
    }
}
