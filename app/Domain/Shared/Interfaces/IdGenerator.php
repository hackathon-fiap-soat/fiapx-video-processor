<?php

declare(strict_types=1);

namespace App\Domain\Shared\Interfaces;

interface IdGenerator
{
    /** UUID v7 (ordenável no tempo). */
    public function next(): string;
}
