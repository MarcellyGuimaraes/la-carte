<?php

namespace Tests\Feature\Resilience;

use App\Jobs\PublishMenu;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\WithTwoTenants;
use Tests\Support\FaultyDisk;
use Tests\TestCase;
use Throwable;

/**
 * Publicação sob falha: o current.json nunca pode apontar para uma versão
 * ausente ou quebrada, e o estado precisa se recuperar sozinho no retry.
 *
 * Invariante checada em quase todo teste (assertPointerIsHealthy): o ponteiro
 * aponta para um v{n}.json que existe, é JSON válido e tem o formato do menu.
 */
#[Group('resilience')]
class PublishMenuResilienceTest extends TestCase
{
    use WithTwoTenants;

    private const SLUG = 'bar-do-tonho';

    private FaultyDisk $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTwoTenants();
        $this->disk = FaultyDisk::replace(config('filesystems.snapshot_disk'));
    }

    /* ---- Falha no meio: ponteiro nunca aponta para versão quebrada ---- */

    public function test_failure_writing_the_version_keeps_pointer_and_database_on_the_previous_version(): void
    {
        $this->publish();
        $this->renameFirstItem($this->tonho, 'Chopp mais caro');
        $this->disk->failPutWhen(fn (string $path) => str_ends_with($path, '/v2.json'));

        $this->assertPublishFails();

        $this->assertSame(1, $this->pointer());
        $this->disk->assertMissing(PublishMenu::versionPath(self::SLUG, 2));
        $this->assertSame(1, $this->databaseVersion());
        $this->assertPointerIsHealthy();
    }

    public function test_failure_writing_the_pointer_keeps_it_on_the_previous_version(): void
    {
        $this->publish();
        $this->renameFirstItem($this->tonho, 'Chopp mais caro');
        $this->disk->failPutWhen(fn (string $path) => str_ends_with($path, 'current.json'));

        $this->assertPublishFails();

        $this->assertSame(1, $this->pointer());
        $this->assertSame(1, $this->databaseVersion());
        $this->assertPointerIsHealthy();
    }

    public function test_next_publish_after_a_pointer_failure_never_reuses_the_orphan_number(): void
    {
        $this->publish();
        $this->renameFirstItem($this->tonho, 'Chopp mais caro');
        $this->disk->failPutWhen(fn (string $path) => str_ends_with($path, 'current.json'));
        $this->assertPublishFails();

        /* v2 ficou órfão no storage. O retry não pode reescrevê-lo. */
        $orphan = $this->disk->get(PublishMenu::versionPath(self::SLUG, 2));
        $this->disk->heal();
        $this->renameFirstItem($this->tonho, 'Chopp ainda mais caro');

        $this->publish();

        $this->assertSame(3, $this->pointer());
        $this->assertSame($orphan, $this->disk->get(PublishMenu::versionPath(self::SLUG, 2)));
        $this->assertPointerIsHealthy();
    }

    public function test_corrupted_version_upload_never_becomes_the_published_version(): void
    {
        $this->publish();
        $this->renameFirstItem($this->tonho, 'Chopp mais caro');
        /* O storage diz que gravou, mas gravou pela metade. */
        $this->disk->truncatePutWhen(fn (string $path) => str_ends_with($path, '/v2.json'));

        $this->assertPublishFails();

        $this->assertSame(1, $this->pointer());
        $this->assertSame(1, $this->databaseVersion());
        $this->assertPointerIsHealthy();
    }

    public function test_publish_holds_the_restaurant_lock_while_writing(): void
    {
        /*
         * Enquanto o job grava, outra conexão tenta travar a mesma linha sem
         * esperar. Tem que falhar: é o que serializa dois "Publicar" seguidos.
         */
        $lockedByAnotherPublish = null;
        $this->disk->beforePut(function (string $path) use (&$lockedByAnotherPublish): void {
            if (! str_ends_with($path, '/v1.json')) {
                return;
            }

            try {
                DB::connection('pgsql_owner')->select('select id from tenants where id = ? for update nowait', [$this->tonho]);
                $lockedByAnotherPublish = false;
            } catch (QueryException $e) {
                $lockedByAnotherPublish = str_contains($e->getMessage(), '55P03');
            }
        });

        $this->publish();

        $this->assertTrue($lockedByAnotherPublish, 'a linha do restaurante não estava travada durante a gravação');
    }

    /* ---- Faxina ---- */

    public function test_cleanup_failure_does_not_undo_a_successful_publication(): void
    {
        foreach (range(1, 3) as $round) {
            $this->renameFirstItem($this->tonho, "rodada {$round}");
            $this->publish();
        }
        $this->renameFirstItem($this->tonho, 'rodada 4');
        $this->disk->failDeletes();

        /* O cardápio novo já está no ar: falhar a faxina não pode falhar o job. */
        $this->publish();

        $this->assertSame(4, $this->pointer());
        $this->assertSame(4, $this->databaseVersion());
        $this->assertPointerIsHealthy();
    }

    public function test_cleanup_catches_up_on_the_next_publication(): void
    {
        foreach (range(1, 4) as $round) {
            $this->renameFirstItem($this->tonho, "rodada {$round}");
            $round === 4 ? $this->disk->failDeletes() : $this->disk->heal();
            $this->publish();
        }
        $this->disk->heal();
        $this->renameFirstItem($this->tonho, 'rodada 5');

        $this->publish();

        $this->assertSame([3, 4, 5], $this->storedVersions());
    }

    public function test_retention_keeps_at_most_three_versions_and_always_the_pointed_one(): void
    {
        foreach (range(1, 6) as $round) {
            $this->renameFirstItem($this->tonho, "rodada {$round}");
            $this->publish();

            $this->assertLessThanOrEqual(PublishMenu::KEEP_VERSIONS, count($this->storedVersions()));
            $this->assertContains($this->pointer(), $this->storedVersions());
            $this->assertPointerIsHealthy();
        }
    }

    public function test_retention_counts_orphans_but_never_drops_the_pointed_version(): void
    {
        $this->publish();
        /* Três publicações anteriores que falharam no ponteiro deixaram órfãos. */
        foreach ([2, 3, 4] as $orphan) {
            $this->disk->put(PublishMenu::versionPath(self::SLUG, $orphan), '{}');
        }
        $this->renameFirstItem($this->tonho, 'depois dos órfãos');

        $this->publish();

        $this->assertSame(5, $this->pointer());
        $this->assertContains(5, $this->storedVersions());
        $this->assertCount(PublishMenu::KEEP_VERSIONS, $this->storedVersions());
        $this->assertPointerIsHealthy();
    }

    /* ---- Idempotência ---- */

    public function test_republishing_without_changes_creates_no_new_version(): void
    {
        $this->publish();
        $firstSnapshot = $this->disk->get(PublishMenu::versionPath(self::SLUG, 1));

        $this->publish();
        $this->publish();

        $this->assertSame([1], $this->storedVersions());
        $this->assertSame(1, $this->pointer());
        $this->assertSame(1, $this->databaseVersion());
        /* Nem o generated_at muda: o arquivo imutável não foi tocado. */
        $this->assertSame($firstSnapshot, $this->disk->get(PublishMenu::versionPath(self::SLUG, 1)));
    }

    public function test_retrying_a_job_that_already_published_is_a_no_op(): void
    {
        /* O mesmo job reprocessado (ex.: worker morreu depois de publicar). */
        $job = new PublishMenu($this->tonho);
        $job->handle();
        $job->handle();

        $this->assertSame([1], $this->storedVersions());
        $this->assertPointerIsHealthy();
    }

    public function test_republishing_after_a_change_creates_the_next_version(): void
    {
        $this->publish();
        $this->renameFirstItem($this->tonho, 'Chopp mais caro');

        $this->publish();

        $this->assertSame([1, 2], $this->storedVersions());
        $this->assertSame(2, $this->pointer());
    }

    public function test_no_op_republish_heals_a_database_version_that_fell_behind(): void
    {
        $this->publish();
        /* Ponteiro gravado, mas o commit do banco se perdeu. */
        DB::connection('pgsql_owner')->table('tenants')->where('id', $this->tonho)->update(['current_version' => 0]);

        $this->publish();

        $this->assertSame([1], $this->storedVersions());
        $this->assertSame(1, $this->databaseVersion());
    }

    private function publish(): void
    {
        (new PublishMenu($this->tonho))->handle();
    }

    private function assertPublishFails(): void
    {
        try {
            $this->publish();
        } catch (RuntimeException) {
            return;
        } catch (Throwable $e) {
            $this->fail('falhou com erro inesperado: '.$e::class.': '.$e->getMessage());
        }

        $this->fail('a publicação deveria ter falhado');
    }

    private function pointer(): ?int
    {
        $raw = $this->disk->get(PublishMenu::pointerPath(self::SLUG));

        return $raw === null ? null : json_decode($raw, true, flags: JSON_THROW_ON_ERROR)['version'];
    }

    private function databaseVersion(): int
    {
        return DB::connection('pgsql_owner')->table('tenants')->where('id', $this->tonho)->value('current_version');
    }

    /** @return list<int> */
    private function storedVersions(): array
    {
        $versions = [];
        foreach ($this->disk->files('menus/'.self::SLUG) as $path) {
            if (preg_match('#/v(\d+)\.json$#', $path, $m)) {
                $versions[] = (int) $m[1];
            }
        }
        sort($versions);

        return $versions;
    }

    /** O que o celular vai buscar precisa existir e ser um cardápio inteiro. */
    private function assertPointerIsHealthy(): void
    {
        $version = $this->pointer();
        $this->assertNotNull($version, 'current.json ausente');

        $raw = $this->disk->get(PublishMenu::versionPath(self::SLUG, $version));
        $this->assertNotNull($raw, "current.json aponta para v{$version}, que não existe");

        $menu = json_decode($raw, true);
        $this->assertIsArray($menu, "v{$version}.json não é JSON válido");
        $this->assertSame(['tenant', 'generated_at', 'categories'], array_keys($menu), "v{$version}.json fora do contrato");
    }
}
