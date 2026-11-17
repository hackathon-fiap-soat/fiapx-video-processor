<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Processing\Ports\Workspace;
use App\Application\Processing\ProcessorSettings;
use App\Domain\Processing\Interfaces\FrameArchiver;
use App\Domain\Processing\Interfaces\FrameExtractor;
use App\Domain\Processing\Interfaces\ObjectStorage;
use App\Domain\Processing\Interfaces\ReplyPublisher;
use App\Domain\Shared\Interfaces\Clock;
use App\Domain\Shared\Interfaces\IdGenerator;
use App\Infrastructure\Config\ParameterResolver;
use App\Infrastructure\Media\FfmpegFrameExtractor;
use App\Infrastructure\Media\ZipFrameArchiver;
use App\Infrastructure\Messaging\CommandQueue;
use App\Infrastructure\Messaging\SqsCommandConsumer;
use App\Infrastructure\Messaging\SqsReplyPublisher;
use App\Infrastructure\Storage\LocalWorkspace;
use App\Infrastructure\Storage\S3ObjectStorage;
use App\Infrastructure\Support\AwsClients;
use App\Infrastructure\Support\LivenessFile;
use App\Infrastructure\Support\RamseyUuidV7Generator;
use App\Infrastructure\Support\SystemClock;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use Aws\Ssm\SsmClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/** Liga cada interface do domínio e da aplicação à implementação da infraestrutura (ADR-003). */
final class RepositoryServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $singletons = [
        ObjectStorage::class => S3ObjectStorage::class,
        ReplyPublisher::class => SqsReplyPublisher::class,
        FrameArchiver::class => ZipFrameArchiver::class,
        CommandQueue::class => SqsCommandConsumer::class,
        Workspace::class => LocalWorkspace::class,
        Clock::class => SystemClock::class,
        IdGenerator::class => RamseyUuidV7Generator::class,
    ];

    public function register(): void
    {
        $this->app->singleton(SsmClient::class, fn () => new SsmClient(AwsClients::options()));
        $this->app->singleton(SqsClient::class, fn () => new SqsClient(AwsClients::options()));
        $this->app->singleton(S3Client::class, fn () => new S3Client(AwsClients::options()));

        $this->app->singleton(ParameterResolver::class, fn (Application $app) => new ParameterResolver(
            fn () => $app->make(SsmClient::class),
            (string) config('fiapx.env'),
            (array) config('fiapx.parameters'),
        ));

        $this->app->singleton(FrameExtractor::class, fn () => new FfmpegFrameExtractor(
            (string) config('fiapx.processor.ffmpeg_binary'),
            (int) config('fiapx.processor.ffmpeg_timeout_seconds'),
        ));
        $this->app->singleton(LocalWorkspace::class, fn () => new LocalWorkspace((string) config('fiapx.processor.work_dir')));
        $this->app->singleton(LivenessFile::class, fn () => new LivenessFile(
            (string) config('fiapx.processor.alive_file'),
            (int) config('fiapx.processor.alive_max_age_seconds'),
        ));
        $this->app->singleton(ProcessorSettings::class, fn () => new ProcessorSettings(
            workerId: (string) config('fiapx.processor.worker_id'),
            maxAttempts: (int) config('fiapx.processor.max_attempts'),
        ));
    }
}
