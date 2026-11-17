<?php

declare(strict_types=1);

namespace App\Infrastructure\Media;

use App\Domain\Processing\Exceptions\ProcessingFailed;
use App\Domain\Processing\Exceptions\TransientFailure;
use App\Domain\Processing\Interfaces\FrameExtractor;
use App\Domain\Processing\ValueObjects\ErrorCode;
use App\Domain\Processing\ValueObjects\FrameSet;
use App\Domain\Processing\ValueObjects\FramesPerSecond;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Extração com o binário do ffmpeg (Symfony Process), como no projeto base:
 * ffmpeg -i <vídeo> -vf fps=1 -y <dir>/frame_%04d.png
 *
 * Saída com erro ou timeout → FFMPEG_ERROR; nenhum frame (vídeo com menos de 1 s) → NO_FRAMES. As duas são
 * falhas definitivas: tentar de novo com o mesmo arquivo dá o mesmo resultado. Morto por sinal → transitória.
 */
final readonly class FfmpegFrameExtractor implements FrameExtractor
{
    public const FRAME_PATTERN = 'frame_%04d.png';

    public function __construct(private string $binary = 'ffmpeg', private int $timeoutSeconds = 1500, private int $pollMicroseconds = 200_000) {}

    public function extract(string $videoPath, string $outputDir, FramesPerSecond $fps, ?\Closure $whileRunning = null): FrameSet
    {
        if (! is_dir($outputDir) && ! mkdir($outputDir, 0o775, true) && ! is_dir($outputDir)) {
            throw new \RuntimeException("Não foi possível criar {$outputDir}.");
        }
        $process = new Process([
            $this->binary, '-hide_banner', '-nostdin', '-loglevel', 'error',
            '-i', $videoPath, '-vf', "fps={$fps->value}", '-y', $outputDir.'/'.self::FRAME_PATTERN,
        ]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                if ($whileRunning !== null) {
                    $whileRunning();
                }
                usleep($this->pollMicroseconds);
            }
        } catch (ProcessTimedOutException $e) {
            throw ProcessingFailed::because(ErrorCode::FfmpegError, "ffmpeg passou de {$this->timeoutSeconds} s e foi interrompido.", $e);
        }

        if ($process->hasBeenSignaled()) {
            // Morto de fora (ex.: Ctrl+C no terminal, OOM): não é culpa do vídeo, então pode tentar de novo.
            throw TransientFailure::because(ErrorCode::FfmpegError, "ffmpeg interrompido pelo sinal {$process->getTermSignal()}.");
        }
        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());
            $lines = $stderr === '' ? [] : preg_split('/\R/', $stderr);
            $last = is_array($lines) && $lines !== [] ? (string) end($lines) : 'sem detalhes';

            throw ProcessingFailed::because(ErrorCode::FfmpegError, "ffmpeg saiu com código {$process->getExitCode()}: {$last}");
        }

        $files = glob($outputDir.'/frame_*.png') ?: [];
        natsort($files);
        if ($files === []) {
            throw ProcessingFailed::because(ErrorCode::NoFrames, 'ffmpeg não gerou nenhum frame (vídeo com menos de 1 segundo ou sem trilha de vídeo).');
        }

        return new FrameSet($outputDir, array_values($files));
    }
}
