<?php

declare(strict_types=1);

namespace App\Infrastructure\Media;

use App\Domain\Processing\Interfaces\FrameArchiver;
use App\Domain\Processing\ValueObjects\FrameSet;

/**
 * Zip com ZipArchive em streaming: addFile só guarda o caminho e o conteúdo é lido do disco no close(), sem
 * carregar os frames na memória. Fecha e reabre o arquivo a cada lote para não estourar o limite de arquivos
 * abertos em vídeos longos. Os PNG já são comprimidos: ficam sem recompressão (CM_STORE), que poupa CPU.
 */
final readonly class ZipFrameArchiver implements FrameArchiver
{
    public function __construct(private int $batchSize = 500) {}

    public function archive(FrameSet $frames, string $zipPath): int
    {
        if (is_file($zipPath)) {
            unlink($zipPath);
        }
        $zip = new \ZipArchive;
        foreach (array_chunk($frames->files, max(1, $this->batchSize)) as $batch) {
            $this->open($zip, $zipPath);
            foreach ($batch as $file) {
                $name = basename($file);
                if (! is_file($file) || ! $zip->addFile($file, $name)) {
                    throw new \RuntimeException("Não foi possível adicionar {$name} ao zip.");
                }
                $zip->setCompressionName($name, \ZipArchive::CM_STORE);
            }
            if (! $zip->close()) {
                throw new \RuntimeException("Não foi possível gravar o zip {$zipPath}: {$zip->getStatusString()}");
            }
        }
        clearstatcache(true, $zipPath);

        return (int) filesize($zipPath);
    }

    private function open(\ZipArchive $zip, string $path): void
    {
        $result = $zip->open($path, \ZipArchive::CREATE);
        if ($result !== true) {
            throw new \RuntimeException("Não foi possível abrir o zip {$path} (código {$result}).");
        }
    }
}
