<?php

declare(strict_types=1);

namespace App\Application\Processing;

final readonly class ProcessorSettings
{
    public function __construct(public string $workerId = 'fiapx-video-processor', public int $maxAttempts = 3) {}
}
