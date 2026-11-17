<?php

declare(strict_types=1);

namespace App\Application\Processing\Ports;

/**
 * Mantém a mensagem invisível na fila enquanto o vídeo é processado (ADR-009). Pode ser chamado com
 * frequência: a implementação decide quando estender a visibility.
 */
interface Heartbeat
{
    public function beat(): void;
}
