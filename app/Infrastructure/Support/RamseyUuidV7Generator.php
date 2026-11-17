<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

use App\Domain\Shared\Interfaces\IdGenerator;
use Ramsey\Uuid\Uuid;

/** UUID v7 com ramsey/uuid (ADR-003). */
final class RamseyUuidV7Generator implements IdGenerator
{
    public function next(): string
    {
        return Uuid::uuid7()->toString();
    }
}
