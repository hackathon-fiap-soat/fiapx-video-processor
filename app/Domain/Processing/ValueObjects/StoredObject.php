<?php

declare(strict_types=1);

namespace App\Domain\Processing\ValueObjects;

/** Objeto já gravado no armazenamento: tamanho e metadados (chaves em minúsculas). */
final readonly class StoredObject
{
    /** @param array<string, string> $metadata */
    public function __construct(public int $sizeBytes, public array $metadata = []) {}

    /** Metadado inteiro não negativo, ou null se ausente ou inválido. */
    public function intMetadata(string $name): ?int
    {
        $value = $this->metadata[strtolower($name)] ?? null;

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
