<?php

declare(strict_types=1);

namespace App\Domain\Processing\Exceptions;

use App\Domain\Processing\ValueObjects\ErrorCode;
use App\Domain\Processing\ValueObjects\ProcessingError;

/**
 * Falha transitória (S3, rede): a mensagem não é apagada e volta para a fila. Na última tentativa
 * (maxReceiveCount) o worker a trata como definitiva, com o código guardado aqui.
 */
final class TransientFailure extends \RuntimeException
{
    public function __construct(public readonly ProcessingError $error, ?\Throwable $previous = null)
    {
        parent::__construct($error->message, 0, $previous);
    }

    public static function because(ErrorCode $code, string $message, ?\Throwable $previous = null): self
    {
        return new self(new ProcessingError($code, $message), $previous);
    }
}
