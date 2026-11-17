<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

use App\Domain\Shared\Interfaces\Clock;

final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
