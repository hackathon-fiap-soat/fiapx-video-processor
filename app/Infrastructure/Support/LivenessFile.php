<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

/**
 * Liveness do worker sem HTTP: o laço do consumidor e o heartbeat tocam o arquivo; o probe
 * (php artisan processor:alive) falha se ele ficar parado por mais que o limite.
 */
final readonly class LivenessFile
{
    public function __construct(public string $path, private int $maxAgeSeconds = 600) {}

    public function touch(): void
    {
        @touch($this->path);
    }

    public function isFresh(?int $now = null): bool
    {
        clearstatcache(true, $this->path);
        $mtime = @filemtime($this->path);

        return $mtime !== false && (($now ?? time()) - $mtime) <= $this->maxAgeSeconds;
    }
}
