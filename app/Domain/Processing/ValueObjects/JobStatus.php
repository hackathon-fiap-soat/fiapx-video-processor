<?php

declare(strict_types=1);

namespace App\Domain\Processing\ValueObjects;

enum JobStatus: string
{
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
}
