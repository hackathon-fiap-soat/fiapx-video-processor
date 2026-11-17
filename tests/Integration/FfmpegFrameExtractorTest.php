<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Processing\Exceptions\ProcessingFailed;
use App\Domain\Processing\Exceptions\TransientFailure;
use App\Domain\Processing\ValueObjects\ErrorCode;
use App\Domain\Processing\ValueObjects\FramesPerSecond;
use App\Infrastructure\Media\FfmpegFrameExtractor;
use App\Infrastructure\Media\ZipFrameArchiver;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;

/** ffmpeg de verdade (o mesmo binário da imagem) com vídeos gerados na hora pelo próprio ffmpeg (testsrc). */
final class FfmpegFrameExtractorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Fixtures::tempDir();
    }

    protected function tearDown(): void
    {
        Fixtures::removeDir($this->dir);
    }

    public function test_video_de_3_segundos_gera_3_frames_e_o_zip(): void
    {
        $video = Fixtures::video($this->dir.'/v.mp4', 3);
        $ticks = 0;
        $frames = (new FfmpegFrameExtractor(pollMicroseconds: 1000))->extract($video, $this->dir.'/frames', new FramesPerSecond(1), function () use (&$ticks): void {
            $ticks++;
        });

        self::assertSame(3, $frames->count());
        self::assertSame(['frame_0001.png', 'frame_0002.png', 'frame_0003.png'], array_map('basename', $frames->files));
        self::assertGreaterThan(0, $ticks, 'o heartbeat precisa ser chamado enquanto o ffmpeg roda');
        self::assertSame("\x89PNG", substr((string) file_get_contents($frames->files[0]), 0, 4));

        $size = (new ZipFrameArchiver)->archive($frames, $this->dir.'/frames.zip');
        $zip = new \ZipArchive;
        self::assertTrue($zip->open($this->dir.'/frames.zip'));
        self::assertSame(3, $zip->numFiles);
        self::assertSame(filesize($this->dir.'/frames.zip'), $size);
        $zip->close();
    }

    public function test_video_corrompido_e_ffmpeg_error(): void
    {
        file_put_contents($this->dir.'/v.mp4', 'isto não é um vídeo');
        try {
            (new FfmpegFrameExtractor)->extract($this->dir.'/v.mp4', $this->dir.'/frames', new FramesPerSecond(1));
            self::fail('deveria falhar');
        } catch (ProcessingFailed $e) {
            self::assertSame(ErrorCode::FfmpegError, $e->error->code);
            self::assertStringContainsString('ffmpeg saiu com código', $e->error->message);
        }
    }

    public function test_video_menor_que_1_segundo_e_no_frames(): void
    {
        // Vídeo de 0,3 s sem o frame em t=0 (o fps=1 não gera nenhum).
        exec(sprintf('ffmpeg -hide_banner -loglevel error -nostdin -f lavfi -i testsrc=duration=0.3:rate=10 -y %s', escapeshellarg($this->dir.'/v.mp4')));
        try {
            (new FfmpegFrameExtractor)->extract($this->dir.'/v.mp4', $this->dir.'/frames', new FramesPerSecond(1));
            self::fail('deveria falhar');
        } catch (ProcessingFailed $e) {
            self::assertSame(ErrorCode::NoFrames, $e->error->code);
        }
    }

    public function test_timeout_e_ffmpeg_error(): void
    {
        $fake = $this->fakeBinary("sleep 5\n");
        try {
            (new FfmpegFrameExtractor($fake, 1, 10_000))->extract('/x.mp4', $this->dir.'/frames', new FramesPerSecond(1));
            self::fail('deveria falhar');
        } catch (ProcessingFailed $e) {
            self::assertSame(ErrorCode::FfmpegError, $e->error->code);
            self::assertStringContainsString('passou de 1 s', $e->error->message);
        }
    }

    public function test_ffmpeg_morto_por_sinal_e_transitorio(): void
    {
        $this->expectException(TransientFailure::class);
        (new FfmpegFrameExtractor($this->fakeBinary("kill -9 \$\$\n"), 10, 10_000))->extract('/x.mp4', $this->dir.'/frames', new FramesPerSecond(1));
    }

    private function fakeBinary(string $script): string
    {
        $path = $this->dir.'/fake-ffmpeg';
        file_put_contents($path, "#!/bin/sh\n".$script);
        chmod($path, 0o755);

        return $path;
    }
}
