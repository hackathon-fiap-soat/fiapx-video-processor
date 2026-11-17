<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Processing\Exceptions\ProcessingFailed;
use App\Domain\Processing\Exceptions\TransientFailure;
use App\Domain\Processing\ValueObjects\ErrorCode;
use App\Domain\Processing\ValueObjects\FrameSet;
use App\Domain\Processing\ValueObjects\FramesPerSecond;
use App\Domain\Processing\ValueObjects\ProcessingError;
use App\Domain\Processing\ValueObjects\SagaReference;
use App\Domain\Processing\ValueObjects\StorageLocation;
use App\Domain\Processing\ValueObjects\StoredObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;

final class ValueObjectsTest extends TestCase
{
    public function test_fps_entre_1_e_30(): void
    {
        self::assertSame(1, (new FramesPerSecond(1))->value);
        $this->expectException(\InvalidArgumentException::class);
        new FramesPerSecond(0);
    }

    public function test_storage_location_convencoes(): void
    {
        $video = StorageLocation::uploadedVideo('fiapx-local-videos', 'uploads/'.Fixtures::VIDEO.'.mkv', Fixtures::VIDEO);
        self::assertSame('mkv', $video->extension());
        self::assertFalse($video->isPrefix());
        self::assertSame('s3://fiapx-local-videos/uploads/'.Fixtures::VIDEO.'.mkv', (string) $video);

        $prefix = StorageLocation::resultsPrefix('fiapx-local-videos', 'results/'.Fixtures::VIDEO.'/', Fixtures::VIDEO);
        self::assertTrue($prefix->isPrefix());
        self::assertSame('results/'.Fixtures::VIDEO.'/frames.zip', $prefix->child('frames.zip')->key);
    }

    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function invalidos(): iterable
    {
        yield 'bucket' => [fn () => new StorageLocation('A', 'x')];
        yield 'chave vazia' => [fn () => new StorageLocation('bucket', '')];
        yield 'chave com ..' => [fn () => new StorageLocation('bucket', 'results/../uploads/x')];
        yield 'chave absoluta' => [fn () => new StorageLocation('bucket', '/etc/passwd')];
        yield 'extensão não aceita' => [fn () => StorageLocation::uploadedVideo('bucket', 'uploads/'.Fixtures::VIDEO.'.exe', Fixtures::VIDEO)];
        yield 'vídeo de outro id' => [fn () => StorageLocation::uploadedVideo('bucket', 'uploads/0192f1c4-0000-7000-8000-000000000000.mp4', Fixtures::VIDEO)];
        yield 'prefixo fora de results' => [fn () => StorageLocation::resultsPrefix('bucket', 'uploads/', Fixtures::VIDEO)];
        yield 'child de objeto' => [fn () => (new StorageLocation('bucket', 'a.zip'))->child('b')];
        yield 'frameset vazio' => [fn () => new FrameSet('/w', [])];
        yield 'uuid inválido' => [fn () => new SagaReference('x', Fixtures::VIDEO, Fixtures::CORRELATION)];
    }

    #[DataProvider('invalidos')]
    public function test_valores_invalidos(\Closure $criar): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $criar();
    }

    public function test_processing_error_normaliza_e_limita_a_mensagem(): void
    {
        self::assertSame('NO_FRAMES', (new ProcessingError(ErrorCode::NoFrames, "  \n "))->message);
        self::assertSame('a b', (new ProcessingError(ErrorCode::FfmpegError, "a\n  b"))->message);
        self::assertSame(2000, mb_strlen((new ProcessingError(ErrorCode::FfmpegError, str_repeat('é', 3000)))->message));
    }

    public function test_excecoes_carregam_o_erro(): void
    {
        $e = ProcessingFailed::because(ErrorCode::NoFrames, 'sem frames');
        self::assertSame(ErrorCode::NoFrames, $e->error->code);
        self::assertSame('sem frames', $e->getMessage());
        self::assertSame(ErrorCode::UploadError, TransientFailure::because(ErrorCode::UploadError, 'x')->error->code);
    }

    public function test_metadados_inteiros(): void
    {
        $o = new StoredObject(10, ['frame-count' => '3', 'duration-ms' => 'abc']);
        self::assertSame(3, $o->intMetadata('Frame-Count'));
        self::assertNull($o->intMetadata('duration-ms'));
        self::assertNull($o->intMetadata('nada'));
    }

    public function test_saga_reference_normaliza_minusculas(): void
    {
        self::assertSame(Fixtures::VIDEO, (new SagaReference(Fixtures::SAGA, strtoupper(Fixtures::VIDEO), Fixtures::CORRELATION))->videoId);
    }
}
