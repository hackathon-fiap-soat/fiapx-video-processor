<?php

declare(strict_types=1);

namespace App\Domain\Processing\ValueObjects;

/** Taxa de extração de frames. O contrato v1 fixa 1 fps (como no projeto base); o VO aceita até 30. */
final readonly class FramesPerSecond
{
    public function __construct(public int $value)
    {
        if ($value < 1 || $value > 30) {
            throw new \InvalidArgumentException('fps deve estar entre 1 e 30.');
        }
    }
}
