<?php

namespace Tests\Feature;

use App\Filament\Resources\Items\Pages\ListItems;
use App\Jobs\PublishMenu;
use App\Models\Item;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\WithTwoTenants;
use Tests\TestCase;

/**
 * Publicação: botão -> job -> v{n}.json + current.json + faxina.
 *
 * Storage falso aqui: cobre versão, conteúdo e faxina. Os cache headers só
 * existem num S3 de verdade e estão em SnapshotCacheHeadersTest (MinIO).
 *
 * O job roda pela conexão da aplicação, sem tenant definido antes, igual ao
 * worker.
 */
class PublishMenuTest extends TestCase
{
    use WithTwoTenants;

    private const SLUG = 'bar-do-tonho';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTwoTenants();
        Storage::fake(config('filesystems.snapshot_disk'));
    }

    public function test_publish_button_queues_the_job_for_the_current_restaurant(): void
    {
        Queue::fake();
        $this->bootPanelAs($this->tonho);

        Livewire::test(ListItems::class)
            ->callAction('publishMenu')
            ->assertNotified();

        Queue::assertPushed(PublishMenu::class, fn (PublishMenu $job): bool => $job->tenantId === $this->tonho);
        /* O botão só enfileira: nada foi publicado ainda. */
        $this->assertSame([], $this->disk()->allFiles());
    }

    public function test_saving_an_item_does_not_publish(): void
    {
        $this->actAsTenant($this->tonho);

        Item::findOrFail($this->firstItemId($this->tonho))->update(['name' => 'Rascunho']);

        $this->assertSame([], $this->disk()->allFiles());
    }

    public function test_first_publish_writes_version_1_and_the_pointer(): void
    {
        $this->publish($this->tonho);

        $this->assertSame(['version' => 1], $this->readJson(PublishMenu::pointerPath(self::SLUG)));
        $this->disk()->assertExists(PublishMenu::versionPath(self::SLUG, 1));
        $this->assertSame(1, $this->ownerTenant($this->tonho)->current_version);
    }

    public function test_snapshot_follows_the_pwa_contract_and_only_has_this_restaurant(): void
    {
        DB::connection('pgsql_owner')->table('items')->where('id', $this->firstItemId($this->tonho))
            ->update(['image_path' => 'itens/1/segredo.jpg', 'image_url' => 'https://cdn.exemplo/a-800.webp']);

        $this->publish($this->tonho);
        $menu = $this->readJson(PublishMenu::versionPath(self::SLUG, 1));

        $this->assertSame(['tenant', 'generated_at', 'categories'], array_keys($menu));
        $this->assertSame(['id' => $this->tonho, 'name' => self::SLUG, 'slug' => self::SLUG], $menu['tenant']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $menu['generated_at']);

        $items = $menu['categories'][0]['items'];
        $this->assertSame(
            ['id', 'name', 'description', 'price_cents', 'image_url', 'featured', 'available', 'sort_order'],
            array_keys($items[0]),
        );
        $this->assertSame('https://cdn.exemplo/a-800.webp', $items[0]['image_url']);
        /* Na ordem do painel. */
        $this->assertSame([1, 2, 3], array_column($items, 'sort_order'));

        $raw = $this->disk()->get(PublishMenu::versionPath(self::SLUG, 1));
        $this->assertStringNotContainsString('segredo.jpg', $raw);
        $this->assertStringNotContainsString('pizzaria-da-nona', $raw);
    }

    public function test_after_four_publishes_only_the_last_three_versions_remain(): void
    {
        foreach (range(1, 4) as $round) {
            $this->renameFirstItem($this->tonho, "rodada {$round}");
            $this->publish($this->tonho);
        }

        $this->assertSame(
            ['menus/bar-do-tonho/current.json', 'menus/bar-do-tonho/v2.json', 'menus/bar-do-tonho/v3.json', 'menus/bar-do-tonho/v4.json'],
            $this->sortedFiles(self::SLUG),
        );
        $this->assertSame(['version' => 4], $this->readJson(PublishMenu::pointerPath(self::SLUG)));
    }

    public function test_cleanup_does_not_touch_another_restaurant(): void
    {
        $this->publish($this->nona);

        foreach (range(1, 4) as $round) {
            $this->renameFirstItem($this->tonho, "rodada {$round}");
            $this->publish($this->tonho);
        }

        $this->disk()->assertExists(PublishMenu::versionPath('pizzaria-da-nona', 1));
    }

    public function test_version_number_is_never_reused_even_if_the_database_lost_it(): void
    {
        /* Uma publicação antiga gravou v7 e falhou antes do commit: banco diz 0. */
        $this->disk()->put(PublishMenu::versionPath(self::SLUG, 7), '{}');

        $this->publish($this->tonho);

        $this->assertSame(['version' => 8], $this->readJson(PublishMenu::pointerPath(self::SLUG)));
        $this->assertSame('{}', $this->disk()->get(PublishMenu::versionPath(self::SLUG, 7)));
    }

    public function test_job_does_not_leave_a_tenant_behind_for_the_next_job(): void
    {
        $this->publish($this->tonho);

        $this->assertSame('', DB::selectOne("select current_setting('app.current_tenant', true) as t")->t);
    }

    private function publish(int $tenantId): void
    {
        (new PublishMenu($tenantId))->handle();
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk(config('filesystems.snapshot_disk'));
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        return json_decode($this->disk()->get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function sortedFiles(string $slug): array
    {
        $files = $this->disk()->files("menus/{$slug}");
        sort($files);

        return $files;
    }

    private function ownerTenant(int $tenantId): object
    {
        return DB::connection('pgsql_owner')->table('tenants')->where('id', $tenantId)->first();
    }

    private function bootPanelAs(int $tenantId): void
    {
        $this->actAsTenant($tenantId);
        $this->actingAs($this->createUser($tenantId));
        Filament::setCurrentPanel('admin');
        Filament::setTenant(Tenant::findOrFail($tenantId));
        Filament::bootCurrentPanel();
    }
}
