<?php

namespace Tests\Feature;

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Items\Pages\CreateItem;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\WithTwoTenants;
use Tests\TestCase;

/**
 * Scoping por restaurante no painel /admin: o que o dono vê e edita.
 * O isolamento no banco, sem o painel, está em RowLevelSecurityTest.
 */
class AdminPanelTenancyTest extends TestCase
{
    use WithTwoTenants;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTwoTenants();
    }

    /*
     * Um teste por dono: dois logins na mesma sessão fariam o
     * AuthenticateSession derrubar o segundo, como deve ser.
     */
    public function test_tonho_sees_only_his_menu(): void
    {
        $this->actingAs($this->createUser($this->tonho))
            ->get('/admin/bar-do-tonho/items')
            ->assertOk()
            ->assertSee('bar-do-tonho item 1')
            ->assertDontSee('pizzaria-da-nona item 1');
    }

    public function test_nona_sees_only_her_menu(): void
    {
        $this->actingAs($this->createUser($this->nona))
            ->get('/admin/pizzaria-da-nona/items')
            ->assertOk()
            ->assertSee('pizzaria-da-nona item 1')
            ->assertDontSee('bar-do-tonho item 1');
    }

    public function test_owner_cannot_open_another_restaurant_by_slug(): void
    {
        $this->actingAs($this->createUser($this->tonho))
            ->get('/admin/pizzaria-da-nona/items')
            ->assertNotFound();
    }

    public function test_owner_cannot_open_edit_page_of_another_restaurant_item(): void
    {
        /* Slug certo, id de registro do outro restaurante. */
        $nonaItem = $this->firstItemId($this->nona);

        $this->actingAs($this->createUser($this->tonho))
            ->get("/admin/bar-do-tonho/items/{$nonaItem}/edit")
            ->assertNotFound();
    }

    public function test_creating_a_category_fills_the_current_tenant(): void
    {
        $this->bootPanelAs($this->tonho);

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Sobremesas', 'sort_order' => 5])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($this->tonho, $this->ownerTable('categories', 'Sobremesas')->tenant_id);
    }

    public function test_item_form_rejects_category_from_another_restaurant(): void
    {
        $this->bootPanelAs($this->tonho);

        /* Simula um request adulterado: o select nunca ofereceria esse id. */
        Livewire::test(CreateItem::class)
            ->fillForm([
                'category_id' => $this->firstCategoryId($this->nona),
                'name' => 'Intruso',
                'price_cents' => '10.00',
                'sort_order' => 1,
            ])
            ->call('create')
            ->assertHasFormErrors(['category_id']);

        $this->assertNull($this->ownerTable('items', 'Intruso'));
    }

    public function test_creating_an_item_with_photo_stores_it_under_the_tenant(): void
    {
        Storage::fake('public');
        $this->bootPanelAs($this->tonho);

        Livewire::test(CreateItem::class)
            ->fillForm([
                'category_id' => $this->firstCategoryId($this->tonho),
                'name' => 'Pudim',
                'price_cents' => '12.50',
                'image_url' => $this->fakePhoto(),
                'sort_order' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = $this->ownerTable('items', 'Pudim');
        $this->assertSame($this->tonho, $item->tenant_id);
        $this->assertSame(1250, $item->price_cents);
        $this->assertStringStartsWith("itens/{$this->tonho}/", $item->image_url);
        Storage::disk('public')->assertExists($item->image_url);
    }

    public function test_owner_cannot_mount_edit_component_for_another_restaurant_item(): void
    {
        $this->bootPanelAs($this->tonho);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(EditItem::class, ['record' => $this->firstItemId($this->nona)]);
    }

    /**
     * Livewire::test não passa pelos middlewares da rota, então o que
     * SetPostgresTenant, IdentifyTenant e SetUpPanel fariam vai à mão.
     * bootCurrentPanel registra o global scope e o observer do tenant.
     */
    private function bootPanelAs(int $tenantId): void
    {
        $this->actAsTenant($tenantId);
        $this->actingAs($this->createUser($tenantId));
        Filament::setCurrentPanel('admin');
        Filament::setTenant(Tenant::findOrFail($tenantId));
        Filament::bootCurrentPanel();
    }

    /** Lê pela conexão do dono, para enxergar o que a RLS esconderia. */
    private function ownerTable(string $table, string $name): ?object
    {
        return DB::connection('pgsql_owner')->table($table)->where('name', $name)->first();
    }

    /** PNG 1x1 de verdade: a extensão GD não está instalada para gerar imagem fake. */
    private function fakePhoto(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'pudim.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAMAASsJTYQAAAAASUVORK5CYII='),
        );
    }
}
