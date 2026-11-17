<?php

use App\Domain\Processing\Exceptions\ProcessingFailed;
use App\Domain\Processing\Exceptions\TransientFailure;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/*
 * Worker sem HTTP: só comandos de console (php artisan processor:consume). Não há banco (stateless,
 * spec §8.5). Os casos de uso não têm try/catch (ADR-003): o laço do consumidor é o único lugar que trata
 * as exceções, porque decide entre confirmar a mensagem e deixá-la voltar para a fila.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(commands: __DIR__.'/../routes/console.php')
    ->withCommands([__DIR__.'/../app/Presentation/Console/Commands'])
    ->withMiddleware(function (Middleware $middleware): void {})
    ->withExceptions(function (Exceptions $exceptions): void {
        // Falhas de processamento esperadas: o consumidor já registra com o contexto da mensagem.
        $exceptions->dontReport([ProcessingFailed::class, TransientFailure::class]);
    })->create();
