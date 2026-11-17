<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

/** Opções comuns dos clientes da AWS: região e, no ambiente local, o endpoint do LocalStack. */
final class AwsClients
{
    /** @return array<string, mixed> */
    public static function options(?string $endpoint = null): array
    {
        $options = ['region' => config('fiapx.aws.region'), 'version' => 'latest'];
        $endpoint ??= config('fiapx.aws.endpoint');
        if (is_string($endpoint) && $endpoint !== '') {
            $options['endpoint'] = $endpoint;
            $options['use_path_style_endpoint'] = true;
        }

        return $options;
    }
}
