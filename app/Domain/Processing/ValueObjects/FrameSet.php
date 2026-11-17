<?php

declare(strict_types=1);

namespace App\Domain\Processing\ValueObjects;

/** Frames extraídos (arquivos PNG em disco, em ordem). Nunca vazio: vídeo sem frames é falha NO_FRAMES. */
final readonly class FrameSet
{
    /** @param list<string> $files caminhos absolutos, na ordem dos frames */
    public function __construct(public string $directory, public array $files)
    {
        if ($files === []) {
            throw new \InvalidArgumentException('Um FrameSet precisa de pelo menos um frame.');
        }
    }

    public function count(): int
    {
        return count($this->files);
    }
}
