<?php

declare(strict_types=1);

namespace App\Application\Messaging;

/** Resposta pronta para a saga-replies.fifo: envelope completo (fiapx-docs/contracts/envelope.schema.json). */
final readonly class OutgoingMessage
{
    /** @param array<string, mixed> $envelope */
    public function __construct(public array $envelope) {}

    public function messageId(): string
    {
        return (string) $this->envelope['message_id'];
    }

    public function type(): string
    {
        return (string) $this->envelope['type'];
    }

    /** MessageGroupId da FIFO: as respostas de uma saga chegam em ordem. */
    public function sagaId(): string
    {
        return (string) $this->envelope['saga_id'];
    }

    public function correlationId(): string
    {
        return (string) $this->envelope['correlation_id'];
    }

    public function toJson(): string
    {
        return json_encode($this->envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
