<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

use Aws\Ssm\SsmClient;

/**
 * Valores de config('fiapx.parameters'): variável de ambiente ou, se vazia, SSM /fiapx/<env>/<chave>.
 * Guarda em memória durante a vida do processo (consumidores e relay ficam de pé por horas).
 */
final class ParameterResolver
{
    /** @var array<string, string> */
    private array $cache = [];

    /** @param array<string, array{0: ?string, 1: string}> $parameters */
    public function __construct(private readonly \Closure $ssmFactory, private readonly string $env, private readonly array $parameters) {}

    public function get(string $name): string
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }
        if (! isset($this->parameters[$name])) {
            throw new \InvalidArgumentException("Parâmetro desconhecido: {$name}.");
        }
        [$fromEnv, $ssmKey] = $this->parameters[$name];
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $this->cache[$name] = str_replace('\n', "\n", $fromEnv);
        }
        /** @var SsmClient $ssm */
        $ssm = ($this->ssmFactory)();
        $value = (string) $ssm->getParameter(['Name' => "/fiapx/{$this->env}/{$ssmKey}"])['Parameter']['Value'];

        return $this->cache[$name] = $value;
    }
}
