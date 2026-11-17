<?php

declare(strict_types=1);

namespace App\Application\Processing\Ports;

/** Diretório de trabalho de um processamento (em /work, emptyDir no Kubernetes). */
interface Workspace
{
    /** Cria (vazio) e devolve o caminho absoluto do diretório. */
    public function create(string $name): string;

    /** Apaga o diretório e tudo dentro dele. */
    public function remove(string $path): void;
}
