<?php

declare(strict_types=1);

namespace Tests\Support;

/** Dados de teste: ids do exemplo do fiapx-docs, envelopes de comando e vídeos gerados com o ffmpeg. */
final class Fixtures
{
    public const VIDEO = '0192f1c4-7a2b-7c3d-8e4f-5a6b7c8d9e0f';

    public const SAGA = '0193a1b2-7d00-7e4f-8a5b-6c7d8e9f0a1d';

    public const CORRELATION = '0193a1b2-7c3d-7e4f-8a5b-6c7d8e9f0a1e';

    public const BUCKET = 'fiapx-prod-videos';

    /**
     * Envelope ProcessVideo v1 (mesmos campos do exemplo do fiapx-docs).
     *
     * @return array<string, mixed>
     */
    public static function processVideo(string $videoId = self::VIDEO, string $bucket = self::BUCKET, string $ext = 'mp4', ?string $sagaId = null): array
    {
        return [
            'message_id' => '0193a1b2-8001-7a00-9b00-'.substr(md5($videoId.$bucket), 0, 12),
            'type' => 'ProcessVideo', 'version' => 1,
            'saga_id' => $sagaId ?? self::SAGA, 'video_id' => $videoId, 'correlation_id' => self::CORRELATION,
            'occurred_at' => '2026-11-16T12:01:00Z',
            'payload' => ['bucket' => $bucket, 'video_key' => "uploads/{$videoId}.{$ext}", 'fps' => 1, 'output_prefix' => "results/{$videoId}/"],
        ];
    }

    /** @return array<string, mixed> */
    public static function cleanupArtifacts(string $videoId = self::VIDEO, string $bucket = self::BUCKET, ?string $sagaId = null): array
    {
        return [
            'message_id' => '0193a1b2-8002-7a00-9b00-'.substr(md5($videoId.$bucket), 0, 12),
            'type' => 'CleanupArtifacts', 'version' => 1,
            'saga_id' => $sagaId ?? self::SAGA, 'video_id' => $videoId, 'correlation_id' => self::CORRELATION,
            'occurred_at' => '2026-11-16T12:02:00Z',
            'payload' => ['bucket' => $bucket, 'prefix' => "results/{$videoId}/"],
        ];
    }

    /** Diretório temporário novo. */
    public static function tempDir(string $prefix = 'fiapx-test'): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(6));
        mkdir($dir, 0o775, true);

        return $dir;
    }

    /** Vídeo de teste gerado com o ffmpeg (testsrc): $seconds segundos, 320x240, 10 fps. */
    public static function video(string $path, float $seconds = 3): string
    {
        $cmd = sprintf('ffmpeg -hide_banner -loglevel error -nostdin -f lavfi -i testsrc=duration=%s:size=320x240:rate=10 -pix_fmt yuv420p -y %s 2>&1',
            $seconds, escapeshellarg($path));
        exec($cmd, $out, $code);
        if ($code !== 0) {
            throw new \RuntimeException('ffmpeg não gerou o vídeo de teste: '.implode("\n", $out));
        }

        return $path;
    }

    public static function removeDir(string $dir): void
    {
        if (is_dir($dir)) {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }
}
