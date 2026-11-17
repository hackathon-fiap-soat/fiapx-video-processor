<?php

declare(strict_types=1);

namespace App\Application\Processing\UseCases;

use App\Application\Processing\DTOs\CleanupArtifactsCommand;
use App\Domain\Processing\Exceptions\TransientFailure;
use App\Domain\Processing\Interfaces\ObjectStorage;
use App\Domain\Processing\Interfaces\ReplyPublisher;

/**
 * Compensação do passo ProcessVideo (saga.md): apaga results/{video_id}/ e responde ArtifactsCleaned.
 * Idempotente: sem objetos, responde com deleted_objects = 0.
 */
final readonly class CleanupArtifactsUseCase
{
    public function __construct(private ObjectStorage $storage, private ReplyPublisher $replies) {}

    /** @throws TransientFailure */
    public function execute(CleanupArtifactsCommand $command): int
    {
        $deleted = $this->storage->deletePrefix($command->prefix);
        $this->replies->artifactsCleaned($command->saga, $deleted);

        return $deleted;
    }
}
