<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Infrastructure\Config\ParameterResolver;
use Aws\Sqs\SqsClient;

/** processing-commands no SQS: uma mensagem por vez, long polling e confirmação só depois do trabalho. */
final readonly class SqsCommandConsumer implements CommandQueue
{
    public function __construct(private SqsClient $sqs, private ParameterResolver $parameters) {}

    public function receive(int $waitSeconds): ?ReceivedCommand
    {
        $result = $this->sqs->receiveMessage([
            'QueueUrl' => $this->queueUrl(),
            'WaitTimeSeconds' => max(0, min(20, $waitSeconds)),
            'MaxNumberOfMessages' => 1,
            'MessageSystemAttributeNames' => ['ApproximateReceiveCount'],
        ]);
        $m = ($result['Messages'] ?? [])[0] ?? null;
        if (! is_array($m)) {
            return null;
        }

        return new ReceivedCommand(
            (string) $m['MessageId'],
            (string) $m['ReceiptHandle'],
            (string) $m['Body'],
            max(1, (int) ($m['Attributes']['ApproximateReceiveCount'] ?? 1)),
        );
    }

    public function delete(ReceivedCommand $message): void
    {
        $this->sqs->deleteMessage(['QueueUrl' => $this->queueUrl(), 'ReceiptHandle' => $message->receiptHandle]);
    }

    public function extendVisibility(ReceivedCommand $message, int $seconds): void
    {
        $this->sqs->changeMessageVisibility([
            'QueueUrl' => $this->queueUrl(),
            'ReceiptHandle' => $message->receiptHandle,
            'VisibilityTimeout' => max(0, min(43200, $seconds)),
        ]);
    }

    private function queueUrl(): string
    {
        return $this->parameters->get('processing_commands_queue_url');
    }
}
