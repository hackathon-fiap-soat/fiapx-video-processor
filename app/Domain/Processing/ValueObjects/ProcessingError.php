<?php

declare(strict_types=1);

namespace App\Domain\Processing\ValueObjects;

/** Falha do processamento: código do contrato e mensagem técnica (até 2000 caracteres, não exibida ao usuário). */
final readonly class ProcessingError
{
    public const MAX_MESSAGE = 2000;

    public string $message;

    public function __construct(public ErrorCode $code, string $message)
    {
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? '');
        $this->message = $message === '' ? $code->value : mb_substr($message, 0, self::MAX_MESSAGE);
    }
}
