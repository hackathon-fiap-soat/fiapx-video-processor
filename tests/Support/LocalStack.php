<?php

declare(strict_types=1);

namespace Tests\Support;

use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;

/**
 * Recursos temporários no LocalStack do fiapx-infra/local: um bucket e filas só do teste, para não
 * disparar o evento do S3 do bucket real nem disputar mensagens com o fiapx-video-api, se ele estiver de pé.
 */
final class LocalStack
{
    /** @var list<string> */
    private array $buckets = [];

    /** @var list<string> */
    private array $queues = [];

    public function __construct(public readonly S3Client $s3, public readonly SqsClient $sqs) {}

    public function bucket(): string
    {
        $name = 'fiapx-processor-test-'.bin2hex(random_bytes(4));
        $this->s3->createBucket(['Bucket' => $name]);

        return $this->buckets[] = $name;
    }

    public function queue(bool $fifo = false, int $visibility = 300): string
    {
        $name = 'fiapx-processor-test-'.bin2hex(random_bytes(4)).($fifo ? '.fifo' : '');
        $attributes = ['VisibilityTimeout' => (string) $visibility];
        if ($fifo) {
            $attributes += ['FifoQueue' => 'true', 'ContentBasedDeduplication' => 'false'];
        }

        return $this->queues[] = (string) $this->sqs->createQueue(['QueueName' => $name, 'Attributes' => $attributes])['QueueUrl'];
    }

    /**
     * Todas as mensagens da fila (apagando), em ordem de chegada.
     *
     * @return list<array<string, mixed>>
     */
    public function drain(string $queueUrl, int $expected, int $timeoutSeconds = 10): array
    {
        $bodies = [];
        $deadline = time() + $timeoutSeconds;
        while (count($bodies) < $expected && time() < $deadline) {
            $r = $this->sqs->receiveMessage(['QueueUrl' => $queueUrl, 'MaxNumberOfMessages' => 10, 'WaitTimeSeconds' => 1]);
            foreach ($r['Messages'] ?? [] as $m) {
                $bodies[] = json_decode((string) $m['Body'], true, 512, JSON_THROW_ON_ERROR);
                $this->sqs->deleteMessage(['QueueUrl' => $queueUrl, 'ReceiptHandle' => $m['ReceiptHandle']]);
            }
        }

        return $bodies;
    }

    public function cleanup(): void
    {
        foreach ($this->buckets as $bucket) {
            $objects = $this->s3->listObjectsV2(['Bucket' => $bucket])['Contents'] ?? [];
            foreach ($objects as $o) {
                $this->s3->deleteObject(['Bucket' => $bucket, 'Key' => $o['Key']]);
            }
            $this->s3->deleteBucket(['Bucket' => $bucket]);
        }
        foreach ($this->queues as $url) {
            $this->sqs->deleteQueue(['QueueUrl' => $url]);
        }
        $this->buckets = $this->queues = [];
    }
}
