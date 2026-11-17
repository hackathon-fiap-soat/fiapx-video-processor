<?php

declare(strict_types=1);

namespace App\Domain\Processing\Exceptions;

use App\Domain\Processing\ValueObjects\ErrorCode;
use App\Domain\Processing\ValueObjects\ProcessingError;

/**
 * Falha definitiva (vídeo corrompido, sem frames, vídeo inexistente): tentar de novo não resolve. O worker
 * responde VideoProcessingFailed e apaga a mensagem (spec §8.5).
 */
final class ProcessingFailed extends \DomainException
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
