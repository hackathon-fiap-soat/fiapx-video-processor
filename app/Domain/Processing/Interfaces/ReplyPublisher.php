<?php

declare(strict_types=1);

namespace App\Domain\Processing\Interfaces;

use App\Domain\Processing\Entities\ProcessingJob;
use App\Domain\Processing\ValueObjects\SagaReference;

/** Respostas do worker à saga (saga-replies.fifo, contratos v1 do fiapx-docs). */
interface ReplyPublisher
{
    public function processingStarted(ProcessingJob $job, string $workerId): void;

    /** O job precisa estar COMPLETED. */
    public function videoProcessed(ProcessingJob $job): void;

    /** O job precisa estar FAILED. $attempt é o número do recebimento da mensagem (ApproximateReceiveCount). */
    public function videoProcessingFailed(ProcessingJob $job, int $attempt): void;

    public function artifactsCleaned(SagaReference $saga, int $deletedObjects): void;
}
