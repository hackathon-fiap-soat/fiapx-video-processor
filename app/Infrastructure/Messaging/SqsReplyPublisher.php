<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\Messaging\MessageFactory;
use App\Application\Messaging\OutgoingMessage;
use App\Domain\Processing\Entities\ProcessingJob;
use App\Domain\Processing\Interfaces\ReplyPublisher;
use App\Domain\Processing\ValueObjects\SagaReference;
use App\Infrastructure\Config\ParameterResolver;
use Aws\Sqs\SqsClient;
use Psr\Log\LoggerInterface;

/**
 * Publica as respostas na saga-replies.fifo: MessageGroupId = saga_id (ordem por saga) e
 * MessageDeduplicationId = message_id (contratos-de-mensagens).
 */
final readonly class SqsReplyPublisher implements ReplyPublisher
{
    public function __construct(private SqsClient $sqs, private ParameterResolver $parameters, private MessageFactory $messages, private LoggerInterface $log) {}

    public function processingStarted(ProcessingJob $job, string $workerId): void
    {
        $this->send($this->messages->processingStarted($job, $workerId));
    }

    public function videoProcessed(ProcessingJob $job): void
    {
        $this->send($this->messages->videoProcessed($job));
    }

    public function videoProcessingFailed(ProcessingJob $job, int $attempt): void
    {
        $this->send($this->messages->videoProcessingFailed($job, $attempt));
    }

    public function artifactsCleaned(SagaReference $saga, int $deletedObjects): void
    {
        $this->send($this->messages->artifactsCleaned($saga, $deletedObjects));
    }

    private function send(OutgoingMessage $message): void
    {
        $this->sqs->sendMessage([
            'QueueUrl' => $this->parameters->get('saga_replies_queue_url'),
            'MessageBody' => $message->toJson(),
            'MessageGroupId' => $message->sagaId(),
            'MessageDeduplicationId' => $message->messageId(),
            'MessageAttributes' => [
                'type' => ['DataType' => 'String', 'StringValue' => $message->type()],
                'correlation_id' => ['DataType' => 'String', 'StringValue' => $message->correlationId()],
            ],
        ]);
        $this->log->info('resposta publicada', [
            'message_id' => $message->messageId(), 'type' => $message->type(),
            'saga_id' => $message->sagaId(), 'correlation_id' => $message->correlationId(),
            'payload' => $message->envelope['payload'],
        ]);
    }
}
