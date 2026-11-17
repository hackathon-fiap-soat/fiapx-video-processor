<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use App\Application\Processing\DTOs\VideoProcessedMetrics;
use App\Application\Processing\Ports\ProcessingTelemetry;
use App\Infrastructure\Support\NewRelic;

/** Evento customizado VideoProcessed no New Relic (ADR-011). Sem o agente (local, testes), não faz nada. */
final class NewRelicProcessingTelemetry implements ProcessingTelemetry
{
    public const EVENT_VIDEO_PROCESSED = 'VideoProcessed';

    public function videoProcessed(VideoProcessedMetrics $metrics): void
    {
        NewRelic::recordCustomEvent(self::EVENT_VIDEO_PROCESSED, $metrics->toAttributes());
    }
}
