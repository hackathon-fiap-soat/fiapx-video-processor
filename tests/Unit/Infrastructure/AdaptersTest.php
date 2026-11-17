<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Domain\Processing\ValueObjects\FrameSet;
use App\Infrastructure\Config\ParameterResolver;
use App\Infrastructure\Media\ZipFrameArchiver;
use App\Infrastructure\Messaging\ReceivedCommand;
use App\Infrastructure\Messaging\VisibilityHeartbeat;
use App\Infrastructure\Storage\LocalWorkspace;
use App\Infrastructure\Support\LivenessFile;
use Aws\Ssm\SsmClient;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\Fixtures;
use Tests\Support\InMemoryCommandQueue;

final class AdaptersTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Fixtures::tempDir();
    }

    protected function tearDown(): void
    {
        Fixtures::removeDir($this->dir);
    }

    public function test_heartbeat_estende_a_visibility_a_cada_intervalo(): void
    {
        $queue = new InMemoryCommandQueue;
        $message = new ReceivedCommand('m1', 'h1', '{}');
        $t = 0.0;
        $liveness = new LivenessFile($this->dir.'/alive', 60);
        $hb = new VisibilityHeartbeat($queue, $message, new NullLogger, $liveness, 60, 300, function () use (&$t): float {
            return $t;
        });

        $t = 30;
        $hb->beat();
        self::assertSame([], $queue->extended);
        self::assertTrue($liveness->isFresh());
        $t = 61;
        $hb->beat();
        $t = 100;
        $hb->beat();
        $t = 122;
        $hb->beat();
        self::assertSame([['m1', 300], ['m1', 300]], $queue->extended);

        $queue->failOnExtend = true;
        $t = 300;
        $hb->beat(); // falha só vai para o log
        self::assertCount(2, $queue->extended);
    }

    public function test_heartbeat_com_relogio_real(): void
    {
        $queue = new InMemoryCommandQueue;
        (new VisibilityHeartbeat($queue, new ReceivedCommand('m1', 'h1', '{}'), new NullLogger, intervalSeconds: 0))->beat();
        self::assertCount(1, $queue->extended);
    }

    public function test_workspace_cria_limpa_e_purga(): void
    {
        $ws = new LocalWorkspace($this->dir.'/work');
        $path = $ws->create(Fixtures::VIDEO);
        mkdir($path.'/frames');
        file_put_contents($path.'/frames/a.png', 'x');
        // Criar de novo apaga a sobra.
        self::assertSame($path, $ws->create(Fixtures::VIDEO));
        self::assertFalse(is_dir($path.'/frames'));
        file_put_contents($path.'/v.mp4', 'x');
        $ws->remove($path);
        self::assertFalse(is_dir($path));
        $ws->remove($path);

        $ws->create('a');
        $ws->create('b');
        file_put_contents($this->dir.'/work/solto', 'x');
        self::assertSame(3, $ws->purge());
        self::assertSame(0, (new LocalWorkspace($this->dir.'/nao-existe'))->purge());
    }

    public function test_workspace_recusa_nome_perigoso(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LocalWorkspace($this->dir))->create('../etc');
    }

    public function test_liveness(): void
    {
        $l = new LivenessFile($this->dir.'/alive', 10);
        self::assertFalse($l->isFresh());
        $l->touch();
        self::assertTrue($l->isFresh());
        self::assertFalse($l->isFresh(time() + 11));
    }

    public function test_zip_em_lotes_com_todos_os_frames(): void
    {
        $files = [];
        for ($i = 1; $i <= 7; $i++) {
            $files[] = $f = sprintf('%s/frame_%04d.png', $this->dir, $i);
            file_put_contents($f, str_repeat((string) $i, 100));
        }
        $zipPath = $this->dir.'/frames.zip';
        file_put_contents($zipPath, 'sobra antiga');
        $size = (new ZipFrameArchiver(batchSize: 3))->archive(new FrameSet($this->dir, $files), $zipPath);

        self::assertSame(filesize($zipPath), $size);
        $zip = new \ZipArchive;
        self::assertTrue($zip->open($zipPath));
        self::assertSame(7, $zip->numFiles);
        self::assertSame('frame_0001.png', $zip->getNameIndex(0));
        self::assertSame(str_repeat('7', 100), $zip->getFromName('frame_0007.png'));
        $zip->close();
    }

    public function test_zip_falha_com_arquivo_inexistente(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ZipFrameArchiver)->archive(new FrameSet($this->dir, [$this->dir.'/nao-existe.png']), $this->dir.'/f.zip');
    }

    public function test_parameter_resolver_env_e_ssm(): void
    {
        $ssm = Mockery::mock(SsmClient::class);
        $ssm->expects('getParameter')->once()->with(['Name' => '/fiapx/local/sqs/saga-replies/url'])
            ->andReturn(['Parameter' => ['Value' => 'http://sqs/replies.fifo']]);
        $r = new ParameterResolver(fn () => $ssm, 'local', [
            'a' => ['http://env', 'x'],
            'b' => [null, 'sqs/saga-replies/url'],
        ]);
        self::assertSame('http://env', $r->get('a'));
        self::assertSame('http://sqs/replies.fifo', $r->get('b'));
        self::assertSame('http://sqs/replies.fifo', $r->get('b'));
        $this->expectException(\InvalidArgumentException::class);
        $r->get('c');
    }
}
