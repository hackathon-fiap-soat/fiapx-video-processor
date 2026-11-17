<?php

declare(strict_types=1);

namespace App\Domain\Shared\Interfaces;

interface Clock
{
    /** Agora, em UTC. */
    public function now(): \DateTimeImmutable;
}
