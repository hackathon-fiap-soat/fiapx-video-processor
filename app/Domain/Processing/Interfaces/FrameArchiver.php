<?php

declare(strict_types=1);

namespace App\Domain\Processing\Interfaces;

use App\Domain\Processing\ValueObjects\FrameSet;

interface FrameArchiver
{
    /** Grava os frames num zip em $zipPath, lendo e escrevendo em disco (sem carregar tudo na memória). Devolve o tamanho em bytes. */
    public function archive(FrameSet $frames, string $zipPath): int;
}
