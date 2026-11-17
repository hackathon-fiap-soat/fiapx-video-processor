<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Messaging\CommandQueue;
use App\Infrastructure\Messaging\ReceivedCommand;

/** Fila em memória: entrega na ordem, guarda as confirmações e as extensões de visibility. */
final class InMemoryCommandQueue implements CommandQueue
{
    /** @var list<ReceivedCommand> */
    public array $pending = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var list<array{string, int}> */
    public array $extended = [];

    public bool $failOnExtend = false;

    public function push(string $body, int $receiveCount = 1): ReceivedCommand
    {
        $n = count($this->pending) + count($this->deleted) + 1;
        $m = new ReceivedCommand("sqs-{$n}", "handle-{$n}", $body, $receiveCount);
        $this->pending[] = $m;

        return $m;
    }

    public function receive(int $waitSeconds): ?ReceivedCommand
    {
        return array_shift($this->pending);
    }

    public function delete(ReceivedCommand $message): void
    {
        $this->deleted[] = $message->messageId;
    }

    public function extendVisibility(ReceivedCommand $message, int $seconds): void
    {
        if ($this->failOnExtend) {
            throw new \RuntimeException('SQS fora do ar');
        }
        $this->extended[] = [$message->messageId, $seconds];
    }
}
