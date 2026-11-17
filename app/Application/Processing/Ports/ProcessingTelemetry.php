<?php

declare(strict_types=1);

namespace App\Application\Processing\Ports;

use App\Application\Processing\DTOs\VideoProcessedMetrics;

/**
 * Telemetria do processamento (ADR-011, fiapx-docs/07-observabilidade): a aplicação informa os números e a
 * infraestrutura decide para onde mandar (evento customizado do New Relic em produção, nada sem o agente).
 */
interface ProcessingTelemetry
{
    /** Zip gravado no S3 (ou já existente), antes de responder VideoProcessed à saga. */
    public function videoProcessed(VideoProcessedMetrics $metrics): void;
}
