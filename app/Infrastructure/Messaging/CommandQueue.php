<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

/** Fila de comandos do worker (SQS na AWS e no LocalStack; em memória nos testes do consumidor). */
interface CommandQueue
{
    /** Long polling de uma mensagem por vez; null se a fila estiver vazia ao fim da espera. */
    public function receive(int $waitSeconds): ?ReceivedCommand;

    public function delete(ReceivedCommand $message): void;

    /** Nova visibility, em segundos a partir de agora (ChangeMessageVisibility). */
    public function extendVisibility(ReceivedCommand $message, int $seconds): void;
}
