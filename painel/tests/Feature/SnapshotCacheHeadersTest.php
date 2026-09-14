<?php

namespace Tests\Feature;

use App\Jobs\PublishMenu;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\WithTwoTenants;
use Tests\TestCase;

/**
 * O ponto único de falha do offline: os cache headers, conferidos por HTTP
 * num S3 de verdade (MinIO do docker-compose), não num fake.
 *
 * Grava num prefixo próprio do bucket para não misturar com os dados de dev,
 * e apaga no fim. Sem MinIO no ar, o teste é pulado com aviso.
 *
 * @group minio
 */
class SnapshotCacheHeadersTest extends TestCase
{
    use WithTwoTenants;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $endpoint = parse_url((string) config('filesystems.disks.s3.endpoint'));
        $socket = @fsockopen($endpoint['host'] ?? '127.0.0.1', $endpoint['port'] ?? 9000, timeout: 1);

        if ($socket === false) {
            $this->markTestSkipped('MinIO fora do ar: docker compose up -d minio minio-init');
        }

        fclose($socket);

        $this->prefix = 'phpunit-'.bin2hex(random_bytes(4));
        config([
            'filesystems.snapshot_disk' => 's3',
            'filesystems.disks.s3.root' => $this->prefix,
            'filesystems.disks.s3.throw' => true,
        ]);
        Storage::forgetDisk('s3');

        $this->setUpTwoTenants();
    }

    protected function tearDown(): void
    {
        if (isset($this->prefix)) {
            Storage::disk('s3')->deleteDirectory('');
        }

        parent::tearDown();
    }

    public function test_version_is_immutable_and_pointer_is_never_stored(): void
    {
        (new PublishMenu($this->tonho))->handle();

        $version = Http::head(Storage::disk('s3')->url(PublishMenu::versionPath('bar-do-tonho', 1)));
        $pointer = Http::head(Storage::disk('s3')->url(PublishMenu::pointerPath('bar-do-tonho')));

        $this->assertSame(200, $version->status());
        $this->assertSame('public, max-age=31536000, immutable', $version->header('Cache-Control'));
        $this->assertSame('application/json; charset=utf-8', $version->header('Content-Type'));

        $this->assertSame(200, $pointer->status());
        $this->assertSame('no-store', $pointer->header('Cache-Control'));
        $this->assertSame('application/json; charset=utf-8', $pointer->header('Content-Type'));
    }

    public function test_after_four_publishes_the_bucket_keeps_three_versions(): void
    {
        foreach (range(1, 4) as $round) {
            $this->renameFirstItem($this->tonho, "rodada {$round}");
            (new PublishMenu($this->tonho))->handle();
        }

        $files = Storage::disk('s3')->files('menus/bar-do-tonho');
        sort($files);

        $this->assertSame(
            ['menus/bar-do-tonho/current.json', 'menus/bar-do-tonho/v2.json', 'menus/bar-do-tonho/v3.json', 'menus/bar-do-tonho/v4.json'],
            $files,
        );
        $this->assertSame(404, Http::head(Storage::disk('s3')->url(PublishMenu::versionPath('bar-do-tonho', 1)))->status());
    }
}
