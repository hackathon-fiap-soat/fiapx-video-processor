<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Application\Processing\Ports\Workspace;

/** Subdiretórios de /work (emptyDir com sizeLimit no Kubernetes), um por processamento. */
final readonly class LocalWorkspace implements Workspace
{
    public function __construct(private string $baseDir) {}

    public function create(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1 || trim($name, '.') === '') {
            throw new \InvalidArgumentException("Nome de diretório de trabalho inválido: '{$name}'.");
        }
        $path = rtrim($this->baseDir, '/').'/'.$name;
        // Sobra de um processamento interrompido (o emptyDir sobrevive ao reinício do container).
        $this->remove($path);
        if (! mkdir($path, 0o775, true) && ! is_dir($path)) {
            throw new \RuntimeException("Não foi possível criar {$path}.");
        }

        return $path;
    }

    public function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    /** Apaga tudo o que houver no diretório base (sobras de um container anterior). Devolve quantos itens apagou. */
    public function purge(): int
    {
        if (! is_dir($this->baseDir)) {
            return 0;
        }
        $count = 0;
        foreach (new \FilesystemIterator($this->baseDir) as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && ! $item->isLink() ? $this->remove($item->getPathname()) : unlink($item->getPathname());
            $count++;
        }

        return $count;
    }
}
