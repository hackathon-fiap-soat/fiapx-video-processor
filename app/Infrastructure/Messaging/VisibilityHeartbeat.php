<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\Processing\Ports\Heartbeat;
use App\Infrastructure\Support\LivenessFile;
use Psr\Log\LoggerInterface;

/**
 * Heartbeat de visibility (ADR-009): enquanto o vídeo é processado, a cada $intervalSeconds chama
 * ChangeMessageVisibility para +$extensionSeconds, para outro pod não pegar a mesma mensagem. Também toca o
 * arquivo de liveness. Uma falha no heartbeat não interrompe o processamento: só fica no log (no pior caso,
 * a mensagem volta a ficar visível e outro pod a processa; a chave determinística do zip evita duplicar o efeito).
 */
final class VisibilityHeartbeat implements Heartbeat
{
    private float $lastBeat;

    /** @var \Closure(): float */
    private \Closure $now;

    /** @param (\Closure(): float)|null $now relógio monotônico em segundos (injetável nos testes) */
    public function __construct(
        private readonly CommandQueue $queue,
        private readonly ReceivedCommand $message,
        private readonly LoggerInterface $log,
        private readonly ?LivenessFile $liveness = null,
        private readonly int $intervalSeconds = 60,
        private readonly int $extensionSeconds = 300,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): float => hrtime(true) / 1e9;
        $this->lastBeat = ($this->now)();
    }

    public function beat(): void
    {
        $this->liveness?->touch();
        $now = ($this->now)();
        if ($now - $this->lastBeat < $this->intervalSeconds) {
            return;
        }
        $this->lastBeat = $now;
        try {
            $this->queue->extendVisibility($this->message, $this->extensionSeconds);
            $this->log->info('visibility estendida', ['sqs_message_id' => $this->message->messageId, 'seconds' => $this->extensionSeconds]);
        } catch (\Throwable $e) {
            $this->log->warning('falha no heartbeat de visibility', ['sqs_message_id' => $this->message->messageId, 'error' => $e->getMessage()]);
        }
    }
}
