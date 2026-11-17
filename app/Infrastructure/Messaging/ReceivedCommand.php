<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

/** Mensagem recebida da processing-commands. $receiveCount é o ApproximateReceiveCount (1 na primeira entrega). */
final readonly class ReceivedCommand
{
    public function __construct(public string $messageId, public string $receiptHandle, public string $body, public int $receiveCount = 1) {}
}
