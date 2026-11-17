<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

/** Validação de UUID em texto (minúsculas, com hífens). */
final class Uuid
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    public static function assert(string $value, string $what): string
    {
        $value = strtolower(trim($value));
        if (! self::isValid($value)) {
            throw new \InvalidArgumentException("{$what} inválido.");
        }

        return $value;
    }
}
