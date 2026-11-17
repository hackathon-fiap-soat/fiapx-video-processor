<?php

declare(strict_types=1);

namespace App\Application\Messaging;

use App\Application\Processing\DTOs\CleanupArtifactsCommand;
use App\Application\Processing\DTOs\ProcessVideoCommand;
use App\Domain\Processing\ValueObjects\SagaReference;
use App\Domain\Shared\ValueObjects\Uuid;

/**
 * Comando recebido na processing-commands. Consumidor tolerante: lê só os campos que usa, ignora os
 * desconhecidos e aceita a versão 1 (a única existente).
 */
final class IncomingCommand
{
    public const SUPPORTED_VERSIONS = [1];

    /** @throws \InvalidArgumentException corpo que não é JSON, envelope incompleto, tipo ou versão desconhecidos */
    public static function parse(string $body): ProcessVideoCommand|CleanupArtifactsCommand
    {
        $envelope = json_decode($body, true);
        if (! is_array($envelope)) {
            throw new \InvalidArgumentException('O corpo da mensagem não é um JSON de objeto.');
        }
        $version = $envelope['version'] ?? null;
        if (! in_array($version, self::SUPPORTED_VERSIONS, true)) {
            throw new \InvalidArgumentException('Versão de contrato não suportada: '.json_encode($version).'.');
        }
        $payload = $envelope['payload'] ?? null;
        if (! is_array($payload)) {
            throw new \InvalidArgumentException('Envelope sem payload.');
        }
        $messageId = Uuid::assert(self::field($envelope, 'message_id'), 'message_id');
        $saga = new SagaReference(self::field($envelope, 'saga_id'), self::field($envelope, 'video_id'), self::field($envelope, 'correlation_id'));

        return match ($envelope['type'] ?? null) {
            'ProcessVideo' => ProcessVideoCommand::fromPayload($messageId, $saga, $payload),
            'CleanupArtifacts' => CleanupArtifactsCommand::fromPayload($messageId, $saga, $payload),
            default => throw new \InvalidArgumentException('Tipo de comando desconhecido: '.json_encode($envelope['type'] ?? null).'.'),
        };
    }

    /** @param array<mixed> $envelope */
    private static function field(array $envelope, string $name): string
    {
        $value = $envelope[$name] ?? null;

        return is_string($value) ? $value : '';
    }
}
