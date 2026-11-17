<?php

declare(strict_types=1);

namespace Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Acesso aos contratos do fiapx-docs: FIAPX_DOCS_DIR (padrão ../fiapx-docs, o clone ao lado; no compose,
 * /fiapx-docs; no CI, o checkout do fiapx-docs). Os schemas não são copiados para cá: a fonte é o fiapx-docs.
 */
final class Contracts
{
    private static ?Validator $validator = null;

    public static function dir(string $path = ''): string
    {
        $base = getenv('FIAPX_DOCS_DIR') ?: dirname(__DIR__, 3).'/fiapx-docs';
        if (! is_file($base.'/contracts/envelope.schema.json')) {
            throw new \RuntimeException("fiapx-docs não encontrado em {$base}. Clone ao lado ou defina FIAPX_DOCS_DIR.");
        }

        return rtrim($base.'/'.$path, '/');
    }

    private static function validator(): Validator
    {
        if (self::$validator === null) {
            $v = new Validator;
            $v->setMaxErrors(10);
            $v->resolver()?->registerPrefix('https://github.com/hackathon-fiap-soat/fiapx-docs/contracts/', self::dir('contracts'));
            self::$validator = $v;
        }

        return self::$validator;
    }

    /**
     * Valida um dado (array associativo) contra contracts/<schema>; devolve os erros (vazio = válido).
     *
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    public static function errors(array $data, string $schemaFile): array
    {
        $schema = json_decode((string) file_get_contents(self::dir('contracts/'.$schemaFile)));
        $result = self::validator()->validate(json_decode(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $schema);

        return $result->isValid() ? [] : (new ErrorFormatter)->format($result->error());
    }

    /** @return array<string, mixed> */
    public static function example(string $file): array
    {
        return json_decode((string) file_get_contents(self::dir('contracts/examples/'.$file)), true, 512, JSON_THROW_ON_ERROR);
    }
}
