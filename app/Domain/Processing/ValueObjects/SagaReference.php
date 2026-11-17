<?php

declare(strict_types=1);

namespace App\Domain\Processing\ValueObjects;

use App\Domain\Shared\ValueObjects\Uuid;

/** Identificadores que toda resposta à saga carrega (envelope §7.2): saga, vídeo e correlação. */
final readonly class SagaReference
{
    public string $sagaId;

    public string $videoId;

    public string $correlationId;

    public function __construct(string $sagaId, string $videoId, string $correlationId)
    {
        $this->sagaId = Uuid::assert($sagaId, 'saga_id');
        $this->videoId = Uuid::assert($videoId, 'video_id');
        $this->correlationId = Uuid::assert($correlationId, 'correlation_id');
    }
}
