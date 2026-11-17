<?php

declare(strict_types=1);

namespace App\Domain\Processing\Interfaces;

use App\Domain\Processing\Exceptions\ProcessingFailed;
use App\Domain\Processing\Exceptions\TransientFailure;
use App\Domain\Processing\ValueObjects\StorageLocation;
use App\Domain\Processing\ValueObjects\StoredObject;

/** Bucket de vídeos (S3 na AWS e no LocalStack). */
interface ObjectStorage
{
    /** @throws TransientFailure */
    public function head(StorageLocation $object): ?StoredObject;

    /**
     * Baixa o objeto em stream para um arquivo local.
     *
     * @throws ProcessingFailed DOWNLOAD_ERROR se o objeto não existe
     * @throws TransientFailure DOWNLOAD_ERROR em erro de rede ou do S3
     */
    public function download(StorageLocation $object, string $localPath): void;

    /**
     * Sobe um arquivo local (multipart acima do limite do SDK) com metadados.
     *
     * @param  array<string, string>  $metadata
     *
     * @throws TransientFailure UPLOAD_ERROR
     */
    public function upload(string $localPath, StorageLocation $object, string $contentType, array $metadata = []): void;

    /**
     * Apaga todos os objetos sob o prefixo; devolve quantos apagou.
     *
     * @throws TransientFailure
     */
    public function deletePrefix(StorageLocation $prefix): int;
}
