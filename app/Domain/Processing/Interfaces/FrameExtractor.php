<?php

declare(strict_types=1);

namespace App\Domain\Processing\Interfaces;

use App\Domain\Processing\Exceptions\ProcessingFailed;
use App\Domain\Processing\ValueObjects\FrameSet;
use App\Domain\Processing\ValueObjects\FramesPerSecond;

interface FrameExtractor
{
    /**
     * Extrai os frames do vídeo para $outputDir (frame_0001.png, frame_0002.png...).
     *
     * @param  (\Closure(): void)|null  $whileRunning  chamado periodicamente enquanto a extração roda (heartbeat)
     *
     * @throws ProcessingFailed FFMPEG_ERROR (vídeo ilegível, timeout) ou NO_FRAMES (nenhum frame)
     */
    public function extract(string $videoPath, string $outputDir, FramesPerSecond $fps, ?\Closure $whileRunning = null): FrameSet;
}
