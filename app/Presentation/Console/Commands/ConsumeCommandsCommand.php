<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Messaging\IncomingCommand;
use App\Application\Processing\DTOs\CleanupArtifactsCommand;
use App\Application\Processing\DTOs\ProcessVideoCommand;
use App\Application\Processing\ProcessorSettings;
use App\Application\Processing\UseCases\CleanupArtifactsUseCase;
use App\Application\Processing\UseCases\ProcessVideoUseCase;
use App\Domain\Processing\Exceptions\ProcessingFailed;
use App\Domain\Processing\Exceptions\TransientFailure;
use App\Domain\Processing\ValueObjects\ProcessingError;
use App\Infrastructure\Messaging\CommandQueue;
use App\Infrastructure\Messaging\ReceivedCommand;
use App\Infrastructure\Messaging\VisibilityHeartbeat;
use App\Infrastructure\Storage\LocalWorkspace;
use App\Infrastructure\Support\LivenessFile;
use App\Infrastructure\Support\NewRelic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * Worker (spec §8.5, ADR-009): ReceiveMessage com long polling, uma mensagem por vez; a concorrência vem do
 * número de pods (KEDA). É o único lugar com try/catch em volta dos casos de uso, porque decide o destino da
 * mensagem:
 *
 * - sucesso → DeleteMessage (só depois de subir o zip e publicar a resposta);
 * - falha definitiva → VideoProcessingFailed e DeleteMessage;
 * - falha transitória → a mensagem volta para a fila (DLQ após maxReceiveCount); na última tentativa vira
 *   definitiva, para a saga compensar sem esperar o timeout;
 * - mensagem mal formada → log de erro; volta para a fila até ir para a DLQ.
 *
 * SIGTERM/SIGINT: termina a mensagem atual e não pega outra. O /work é limpo no finally do caso de uso e,
 * na partida, das sobras de um container anterior.
 */
final class ConsumeCommandsCommand extends Command
{
    protected $signature = 'processor:consume {--once : trata o que houver na fila e sai} {--wait= : segundos de long polling (máx. 20)}';

    protected $description = 'Consome ProcessVideo e CleanupArtifacts da processing-commands (um vídeo por vez)';

    private bool $running = true;

    public function handle(CommandQueue $queue, ProcessVideoUseCase $process, CleanupArtifactsUseCase $cleanup, LocalWorkspace $workspace,
        LivenessFile $liveness, ProcessorSettings $settings, LoggerInterface $log): int
    {
        $this->trap([SIGTERM, SIGINT], function (int $signal): void {
            if ($this->running) {
                Log::info('sinal recebido: termina a mensagem atual e para', ['signal' => $signal]);
            }
            $this->running = false;
        });
        $wait = $this->option('wait') !== null ? (int) $this->option('wait') : (int) config('fiapx.processor.wait_seconds');
        $leftovers = $workspace->purge();
        Log::info('worker iniciado', ['worker_id' => $settings->workerId, 'wait_seconds' => $wait, 'work_dir_leftovers' => $leftovers]);

        while ($this->running) {
            $liveness->touch();
            $message = $queue->receive($wait);
            if ($message === null) {
                if ($this->option('once')) {
                    break;
                }

                continue;
            }
            $this->consume($message, $queue, $process, $cleanup, $liveness, $settings, $log);
        }
        Log::info('worker parado', ['worker_id' => $settings->workerId]);

        return self::SUCCESS;
    }

    private function consume(ReceivedCommand $message, CommandQueue $queue, ProcessVideoUseCase $process, CleanupArtifactsUseCase $cleanup,
        LivenessFile $liveness, ProcessorSettings $settings, LoggerInterface $log): void
    {
        $context = ['sqs_message_id' => $message->messageId, 'receive_count' => $message->receiveCount];
        try {
            $command = IncomingCommand::parse($message->body);
        } catch (\InvalidArgumentException $e) {
            // Nunca vai funcionar: não confirma, e a fila a manda para a DLQ após as tentativas.
            Log::error('mensagem inválida; ela vai para a DLQ após as tentativas', $context + ['motivo' => $e->getMessage()]);

            return;
        }

        $type = $command instanceof ProcessVideoCommand ? 'ProcessVideo' : 'CleanupArtifacts';
        Log::withContext([
            'message_id' => $command->messageId, 'type' => $type, 'saga_id' => $command->saga->sagaId,
            'video_id' => $command->saga->videoId, 'correlation_id' => $command->saga->correlationId,
        ]);
        NewRelic::startTransaction("processor/{$type}", ['correlation_id' => $command->saga->correlationId, 'video_id' => $command->saga->videoId]);
        try {
            if ($command instanceof ProcessVideoCommand) {
                $heartbeat = new VisibilityHeartbeat($queue, $message, $log, $liveness,
                    (int) config('fiapx.processor.heartbeat_interval_seconds'), (int) config('fiapx.processor.heartbeat_extension_seconds'));
                $outcome = $process->execute($command, $heartbeat);
                Log::info('vídeo processado', $context + ['outcome' => $outcome->value]);
            } else {
                $deleted = $cleanup->execute($command);
                Log::info('artefatos apagados', $context + ['deleted_objects' => $deleted]);
            }
            $queue->delete($message);
        } catch (ProcessingFailed $e) {
            $this->respondFailure($command, $e->error, $message, $queue, $process, $context + ['motivo' => 'falha definitiva']);
        } catch (TransientFailure $e) {
            if ($command instanceof ProcessVideoCommand && $message->receiveCount >= $settings->maxAttempts) {
                $this->respondFailure($command, $e->error, $message, $queue, $process, $context + ['motivo' => 'falha transitória na última tentativa']);
            } else {
                Log::error('falha transitória; a mensagem volta para a fila', $context + ['error_code' => $e->error->code->value, 'error' => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            NewRelic::noticeError($e);
            Log::error('erro inesperado; a mensagem volta para a fila', $context + ['error' => $e->getMessage(), 'exception' => $e::class]);
        } finally {
            NewRelic::endTransaction();
            Log::withoutContext(['message_id', 'type', 'saga_id', 'video_id', 'correlation_id']);
        }
    }

    /**
     * Responde VideoProcessingFailed e apaga a mensagem. Se a resposta não sair, a mensagem volta para a fila.
     *
     * @param  array<string, mixed>  $context
     */
    private function respondFailure(ProcessVideoCommand|CleanupArtifactsCommand $command, ProcessingError $error, ReceivedCommand $message,
        CommandQueue $queue, ProcessVideoUseCase $process, array $context): void
    {
        $context += ['error_code' => $error->code->value, 'error' => $error->message];
        if (! $command instanceof ProcessVideoCommand) {
            Log::error('falha na limpeza; a mensagem volta para a fila', $context);

            return;
        }
        try {
            $process->fail($command, $error, $message->receiveCount);
            $queue->delete($message);
            Log::warning('processamento falhou; VideoProcessingFailed publicado', $context);
        } catch (\Throwable $e) {
            NewRelic::noticeError($e);
            Log::error('não foi possível publicar a falha; a mensagem volta para a fila', $context + ['publish_error' => $e->getMessage()]);
        }
    }
}
