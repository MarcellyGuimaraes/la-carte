<?php

namespace Tests\Feature;

use App\Enums\Theme;
use App\Filament\Pages\Tenancy\EditBranding;
use App\Jobs\ProcessTenantLogo;
use App\Jobs\PublishMenu;
use App\Models\Tenant;
use Filament\Support\Colors\Color;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithPanels;
use Tests\Concerns\WithTwoTenants;
use Tests\TestCase;

/**
 * Whitelabel: o dono edita a marca em /admin, o painel muda na hora e a mesa
 * só vê depois do "Publicar".
 */
class BrandingTest extends TestCase
{
    use InteractsWithPanels, WithTwoTenants;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTwoTenants();
        Storage::fake('public');
        Storage::fake(config('filesystems.snapshot_disk'));
    }

    public function test_owner_saves_theme_and_color(): void
    {
        $this->bootAdminPanelAs($this->tonho);

        Livewire::test(EditBranding::class)
            ->fillForm(['theme' => Theme::Light, 'brand_color' => '#1A2B5C'])
            ->call('save')
            ->assertHasNoFormErrors();

        $tenant = $this->ownerRow('tenants', $this->tonho);
        $this->assertSame('light', $tenant->theme);
        /* Minúsculo: bate com o CHECK e não gera versão nova só por caixa. */
        $this->assertSame('#1a2b5c', $tenant->brand_color);
    }

    public function test_empty_color_means_default(): void
    {
        DB::connection('pgsql_owner')->table('tenants')->where('id', $this->tonho)->update(['brand_color' => '#1a2b5c']);
        $this->bootAdminPanelAs($this->tonho);

        Livewire::test(EditBranding::class)
            ->fillForm(['brand_color' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($this->ownerRow('tenants', $this->tonho)->brand_color);
    }

    public function test_invalid_color_is_rejected(): void
    {
        $this->bootAdminPanelAs($this->tonho);

        Livewire::test(EditBranding::class)
            ->fillForm(['brand_color' => 'red; background: url(x)'])
            ->call('save')
            ->assertHasFormErrors(['brand_color']);

        $this->assertNull($this->ownerRow('tenants', $this->tonho)->brand_color);
    }

    /** O formulário de marca não é porta para mexer em plano, slug ou ativo. */
    public function test_branding_form_cannot_change_platform_fields(): void
    {
        $this->bootAdminPanelAs($this->tonho);

        Livewire::test(EditBranding::class)
            ->set('data.name', 'Hackeado')
            ->set('data.slug', 'hackeado')
            ->set('data.plan', 'pro')
            ->set('data.active', false)
            ->call('save');

        $tenant = $this->ownerRow('tenants', $this->tonho);
        $this->assertSame('bar-do-tonho', $tenant->name);
        $this->assertSame('bar-do-tonho', $tenant->slug);
        $this->assertSame('free', $tenant->plan);
        $this->assertTrue((bool) $tenant->active);
    }

    public function test_database_rejects_invalid_color_and_theme_from_any_path(): void
    {
        $owner = DB::connection('pgsql_owner')->table('tenants')->where('id', $this->tonho);

        $this->assertRejected(fn () => $owner->clone()->update(['brand_color' => 'red']));
        $this->assertRejected(fn () => $owner->clone()->update(['brand_color' => '#1A2B5C']));
        $this->assertRejected(fn () => $owner->clone()->update(['theme' => 'neon']));
    }

    /** RLS: a linha do tenant de outro restaurante não existe para o dono. */
    public function test_owner_cannot_change_another_restaurant_branding_even_bypassing_the_panel(): void
    {
        $this->actAsTenant($this->tonho);

        $affected = Tenant::query()->whereKey($this->nona)->update(['brand_color' => '#ff0000']);

        $this->assertSame(0, $affected);
        $this->assertNull($this->ownerRow('tenants', $this->nona)->brand_color);
    }

    public function test_owner_cannot_open_branding_of_another_restaurant(): void
    {
        $this->actingAs($this->createUser($this->tonho))
            ->get('/admin/pizzaria-da-nona/profile')
            ->assertNotFound();
    }

    public function test_admin_panel_uses_the_restaurant_color(): void
    {
        DB::connection('pgsql_owner')->table('tenants')->where('id', $this->tonho)->update(['brand_color' => '#1a2b5c']);

        $this->actingAs($this->createUser($this->tonho))
            ->get('/admin/bar-do-tonho')
            ->assertOk()
            ->assertSee(Color::hex('#1a2b5c')[500], escape: false);
    }

    public function test_uploading_a_logo_queues_the_job_and_the_job_stores_the_webp(): void
    {
        Queue::fake();
        $this->bootAdminPanelAs($this->tonho);

        Livewire::test(EditBranding::class)
            ->fillForm(['logo_path' => UploadedFile::fake()->image('logo.png', 1200, 400)])
            ->call('save')
            ->assertHasNoFormErrors();

        $logoPath = $this->ownerRow('tenants', $this->tonho)->logo_path;
        $this->assertStringStartsWith("logos/{$this->tonho}/", $logoPath);

        Queue::assertPushed(ProcessTenantLogo::class, fn (ProcessTenantLogo $job): bool => $job->tenantId === $this->tonho
            && $job->logoPath === $logoPath);

        /* O worker: sem tenant definido antes, pela conexão presa à RLS. */
        $this->actAsTenant(0);
        (new ProcessTenantLogo($this->tonho, $logoPath))->handle();

        $webp = ProcessTenantLogo::variantPath($logoPath, ProcessTenantLogo::WIDTH);
        Storage::disk(config('filesystems.media_disk'))->assertExists($webp);
        $this->assertSame(
            Storage::disk(config('filesystems.media_disk'))->url($webp),
            $this->ownerRow('tenants', $this->tonho)->logo_url,
        );
    }

    public function test_replacing_the_logo_clears_the_old_url_until_the_job_runs(): void
    {
        Queue::fake();
        DB::connection('pgsql_owner')->table('tenants')->where('id', $this->tonho)
            ->update(['logo_path' => 'logos/1/velho.png', 'logo_url' => 'https://cdn.exemplo/velho-512.webp']);
        $this->actAsTenant($this->tonho);

        Tenant::findOrFail($this->tonho)->update(['logo_path' => 'logos/1/novo.png']);

        $this->assertNull($this->ownerRow('tenants', $this->tonho)->logo_url);
    }

    public function test_stale_logo_job_does_not_overwrite_a_newer_logo(): void
    {
        DB::connection('pgsql_owner')->table('tenants')->where('id', $this->tonho)
            ->update(['logo_path' => 'logos/1/novo.png']);
        Storage::disk('public')->put('logos/1/velho.png', UploadedFile::fake()->image('velho.png')->getContent());

        (new ProcessTenantLogo($this->tonho, 'logos/1/velho.png'))->handle();

        $this->assertNull($this->ownerRow('tenants', $this->tonho)->logo_url);
    }

    public function test_snapshot_carries_the_branding(): void
    {
        DB::connection('pgsql_owner')->table('tenants')->where('id', $this->tonho)->update([
            'theme' => 'light',
            'brand_color' => '#1a2b5c',
            'logo_path' => 'logos/1/segredo.png',
            'logo_url' => 'https://cdn.exemplo/logo-512.webp',
        ]);

        (new PublishMenu($this->tonho))->handle();

        $menu = json_decode(
            Storage::disk(config('filesystems.snapshot_disk'))->get(PublishMenu::versionPath('bar-do-tonho', 1)),
            true,
        );

        /* Só o que a PWA usa: o caminho do original (logo_path) não vaza. */
        $this->assertSame([
            'theme' => 'light',
            'brand_color' => '#1a2b5c',
            'logo_url' => 'https://cdn.exemplo/logo-512.webp',
        ], $menu['branding']);
    }

    public function test_changing_only_the_branding_publishes_a_new_version(): void
    {
        (new PublishMenu($this->tonho))->handle();
        DB::connection('pgsql_owner')->table('tenants')->where('id', $this->tonho)->update(['brand_color' => '#1a2b5c']);

        (new PublishMenu($this->tonho))->handle();

        $this->assertSame(2, $this->ownerRow('tenants', $this->tonho)->current_version);
    }

    private function assertRejected(callable $write): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            $this->assertStringContainsString('check constraint', $e->getMessage());

            return;
        }

        $this->fail('O banco aceitou um valor que o CHECK deveria recusar.');
    }
}
