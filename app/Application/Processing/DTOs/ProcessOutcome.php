<?php

declare(strict_types=1);

namespace App\Application\Processing\DTOs;

enum ProcessOutcome: string
{
    case Processed = 'processed';
    /** O zip já existia (mensagem repetida): respondeu VideoProcessed sem reprocessar. */
    case AlreadyProcessed = 'already_processed';
}
