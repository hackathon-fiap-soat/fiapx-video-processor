<?php

declare(strict_types=1);

namespace App\Domain\Processing\ValueObjects;

/** Códigos de falha do VideoProcessingFailed (fiapx-docs/contracts/video-processing-failed.v1.schema.json). */
enum ErrorCode: string
{
    case FfmpegError = 'FFMPEG_ERROR';
    case NoFrames = 'NO_FRAMES';
    case DownloadError = 'DOWNLOAD_ERROR';
    case UploadError = 'UPLOAD_ERROR';
}
