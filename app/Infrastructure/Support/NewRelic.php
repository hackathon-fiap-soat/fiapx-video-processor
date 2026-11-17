<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

/**
 * Transações e eventos customizados do agente New Relic (ADR-011) para um processo que não termina: cada
 * mensagem vira uma transação de background com o correlation_id. Sem o agente (ambiente local, testes), não faz nada.
 */
final class NewRelic
{
    /** @param array<string, scalar> $attributes */
    public static function startTransaction(string $name, array $attributes = []): void
    {
        if (! function_exists('newrelic_start_transaction')) {
            return;
        }
        newrelic_end_transaction();
        newrelic_start_transaction((string) ini_get('newrelic.appname'));
        newrelic_name_transaction($name);
        newrelic_background_job(true);
        foreach ($attributes as $key => $value) {
            newrelic_add_custom_parameter($key, $value);
        }
    }

    /**
     * Evento customizado (consultável por NRQL: SELECT * FROM <tipo>). Devolve false sem o agente.
     *
     * @param  array<string, scalar>  $attributes
     */
    public static function recordCustomEvent(string $type, array $attributes): bool
    {
        if (! function_exists('newrelic_record_custom_event')) {
            return false;
        }
        newrelic_record_custom_event($type, $attributes);

        return true;
    }

    public static function noticeError(\Throwable $e): void
    {
        if (function_exists('newrelic_notice_error')) {
            newrelic_notice_error($e->getMessage(), $e);
        }
    }

    public static function endTransaction(): void
    {
        if (function_exists('newrelic_end_transaction')) {
            newrelic_end_transaction();
        }
    }
}
