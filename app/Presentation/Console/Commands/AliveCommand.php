<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Infrastructure\Support\LivenessFile;
use Illuminate\Console\Command;

/** Liveness probe do worker (sem HTTP): sucesso se o laço do processor:consume tocou o arquivo há pouco. */
final class AliveCommand extends Command
{
    protected $signature = 'processor:alive';

    protected $description = 'Liveness: falha se o processor:consume parou de dar sinal de vida';

    public function handle(LivenessFile $liveness): int
    {
        if ($liveness->isFresh()) {
            return self::SUCCESS;
        }
        $this->error("Sem sinal de vida em {$liveness->path}.");

        return self::FAILURE;
    }
}
