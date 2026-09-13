<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Items\Pages\CreateItem;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Jobs\ProcessItemImage;
use App\Jobs\PublishMenu;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithPanels;
use Tests\Concerns\WithTwoTenants;
use Tests\TestCase;

/**
 * O que o dono digita ou envia: preço, campos proibidos, arquivo, HTML.
 */
#[Group('security')]
class InputValidationTest extends TestCase
{
    use InteractsWithPanels, WithTwoTenants;

    private const XSS_NAME = '<script>alert("xss")</script>';

    private const XSS_DESCRIPTION = '<img src=x onerror=alert(1)>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTwoTenants();
        Storage::fake('public');
        Queue::fake();
    }

    /* ---- Preço ---- */

    /** @return array<string, array{string}> */
    public static function invalidPrices(): array
    {
        return [
            'negativo' => ['-1.00'],
            'não numérico' => ['abc'],
            'acima de R$ 99.999,99' => ['100000.00'],
            'estoura o integer do banco' => ['99999999999'],
        ];
    }

    #[DataProvider('invalidPrices')]
    public function test_invalid_price_is_rejected_by_validation(string $price): void
    {
        $this->bootAdminPanelAs($this->tonho);

        /* Erro de formulário, nunca QueryException (500) vinda do Postgres. */
        Livewire::test(CreateItem::class)
            ->fillForm($this->itemData(['price_cents' => $price]))
            ->call('create')
            ->assertHasFormErrors(['price_cents']);

        $this->assertFalse($this->itemExists('Pudim'));
    }

    public function test_highest_allowed_price_is_accepted(): void
    {
        $this->bootAdminPanelAs($this->tonho);

        Livewire::test(CreateItem::class)
            ->fillForm($this->itemData(['price_cents' => '99999.99']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->itemExists('Pudim'));
    }

    public function test_giant_sort_order_is_rejected_by_validation(): void
    {
        $this->bootAdminPanelAs($this->tonho);

        Livewire::test(CreateItem::class)
            ->fillForm($this->itemData(['sort_order' => '99999999999']))
            ->call('create')
            ->assertHasFormErrors(['sort_order']);
    }

    public function test_giant_category_sort_order_is_rejected_by_validation(): void
    {
        $this->bootAdminPanelAs($this->tonho);

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Gigante', 'sort_order' => '99999999999'])
            ->call('create')
            ->assertHasFormErrors(['sort_order']);
    }

    public function test_edit_with_own_existing_photo_still_saves(): void
    {
        /* Controle da trava de path forjado: o caminho legítimo continua passando. */
        $itemId = $this->firstItemId($this->tonho);
        Storage::disk('public')->put("itens/{$this->tonho}/propria.jpg", UploadedFile::fake()->image('x.jpg')->getContent());
        DB::connection('pgsql_owner')->table('items')->where('id', $itemId)->update(['image_path' => "itens/{$this->tonho}/propria.jpg"]);
        $this->bootAdminPanelAs($this->tonho);

        Livewire::test(EditItem::class, ['record' => $itemId])
            ->fillForm(['name' => 'Renomeado'])
            ->call('save')
            ->assertHasNoFormErrors();

        $item = $this->ownerRow('items', $itemId);
        $this->assertSame('Renomeado', $item->name);
        $this->assertSame("itens/{$this->tonho}/propria.jpg", $item->image_path);
    }

    /* ---- Mass assignment pelo estado do Livewire ---- */

    public function test_creating_an_item_ignores_forged_tenant_id(): void
    {
        $this->bootAdminPanelAs($this->tonho);

        Livewire::test(CreateItem::class)
            ->fillForm($this->itemData())
            ->set('data.tenant_id', $this->nona)
            ->set('data.image_url', 'https://evil.example/x.webp')
            ->call('create')
            ->assertHasNoFormErrors();

        $item = DB::connection('pgsql_owner')->table('items')->where('name', 'Pudim')->first();
        $this->assertSame($this->tonho, $item->tenant_id);
        $this->assertNull($item->image_url);
    }

    public function test_editing_an_item_ignores_forged_protected_fields(): void
    {
        $itemId = $this->firstItemId($this->tonho);
        $this->bootAdminPanelAs($this->tonho);

        Livewire::test(EditItem::class, ['record' => $itemId])
            ->set('data.tenant_id', $this->nona)
            ->set('data.id', 999999)
            ->set('data.image_url', 'https://evil.example/x.webp')
            ->set('data.created_at', '2000-01-01 00:00:00')
            ->call('save')
            ->assertHasNoFormErrors();

        $item = $this->ownerRow('items', $itemId);
        $this->assertSame($this->tonho, $item->tenant_id);
        $this->assertNull($item->image_url);
        $this->assertNotSame('2000-01-01 00:00:00', $item->created_at);
        $this->assertNull($this->ownerRow('items', 999999));
    }

    public function test_creating_a_category_ignores_forged_tenant_id(): void
    {
        $this->bootAdminPanelAs($this->tonho);

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Forjada', 'sort_order' => 1])
            ->set('data.tenant_id', $this->nona)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            $this->tonho,
            DB::connection('pgsql_owner')->table('categories')->where('name', 'Forjada')->value('tenant_id'),
        );
    }

    public function test_publication_version_cannot_be_mass_assigned(): void
    {
        /* current_version é do job de publicação; nenhum formulário a define. */
        $this->assertNull((new Tenant(['current_version' => 999]))->current_version);
    }

    /* ---- Upload ---- */

    /** @return array<string, array{\Closure(): UploadedFile}> */
    public static function rejectedUploads(): array
    {
        return [
            'PDF' => [fn () => UploadedFile::fake()->create('cardapio.pdf', 100, 'application/pdf')],
            'texto com extensão .jpg' => [fn () => UploadedFile::fake()->createWithContent('foto.jpg', 'isto não é uma imagem')],
            'PHP com extensão .jpg' => [fn () => UploadedFile::fake()->createWithContent('foto.jpg', '<?php system($_GET["c"]);')],
            'imagem acima de 4 MB' => [fn () => UploadedFile::fake()->image('grande.jpg', 100, 100)->size(5000)],
            'SVG com script' => [fn () => UploadedFile::fake()->createWithContent(
                'logo.svg',
                '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>',
            )],
        ];
    }

    /** @param  \Closure(): UploadedFile  $file */
    #[DataProvider('rejectedUploads')]
    public function test_bad_upload_is_rejected_before_storage_and_queue(\Closure $file): void
    {
        $this->bootAdminPanelAs($this->tonho);

        Livewire::test(CreateItem::class)
            ->fillForm($this->itemData(['image_path' => $file()]))
            ->call('create')
            ->assertHasFormErrors(['image_path']);

        $this->assertFalse($this->itemExists('Pudim'));
        $this->assertSame([], Storage::disk('public')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_valid_photo_is_accepted(): void
    {
        /* Controle: sem ele, "rejeita tudo" também passaria nos testes acima. */
        $this->bootAdminPanelAs($this->tonho);

        Livewire::test(CreateItem::class)
            ->fillForm($this->itemData(['image_path' => UploadedFile::fake()->image('pudim.jpg', 800, 600)]))
            ->call('create')
            ->assertHasNoFormErrors();

        Queue::assertPushed(ProcessItemImage::class);
    }

    public function test_forged_image_path_pointing_to_another_restaurant_file_is_not_saved(): void
    {
        Storage::disk('public')->put("itens/{$this->nona}/foto-da-nona.jpg", UploadedFile::fake()->image('x.jpg')->getContent());
        $itemId = $this->firstItemId($this->tonho);
        $this->bootAdminPanelAs($this->tonho);

        rescue(fn () => Livewire::test(EditItem::class, ['record' => $itemId])
            ->set('data.image_path', ["itens/{$this->nona}/foto-da-nona.jpg"])
            ->call('save'), report: false);

        $this->assertNull($this->ownerRow('items', $itemId)->image_path);
        Queue::assertNothingPushed();
    }

    public function test_forged_image_path_with_traversal_is_not_saved(): void
    {
        $itemId = $this->firstItemId($this->tonho);
        $this->bootAdminPanelAs($this->tonho);

        rescue(fn () => Livewire::test(EditItem::class, ['record' => $itemId])
            ->set('data.image_path', ['../../.env'])
            ->call('save'), report: false);

        $this->assertNull($this->ownerRow('items', $itemId)->image_path);
        Queue::assertNothingPushed();
    }

    /* ---- XSS ---- */

    public function test_html_in_item_name_is_escaped_in_the_admin_list(): void
    {
        $this->storeXssItem();

        $this->actingAs($this->createUser($this->tonho))
            ->get('/admin/bar-do-tonho/items')
            ->assertOk()
            /* O texto aparece (escapado)... */
            ->assertSee(self::XSS_NAME)
            /* ...mas nunca como tag de verdade. */
            ->assertDontSee(self::XSS_NAME, escape: false);
    }

    public function test_html_in_item_fields_is_escaped_in_the_edit_page(): void
    {
        $itemId = $this->storeXssItem();

        $this->actingAs($this->createUser($this->tonho))
            ->get("/admin/bar-do-tonho/items/{$itemId}/edit")
            ->assertOk()
            ->assertDontSee(self::XSS_NAME, escape: false)
            ->assertDontSee(self::XSS_DESCRIPTION, escape: false);
    }

    public function test_html_in_category_name_is_escaped_in_the_item_form(): void
    {
        DB::connection('pgsql_owner')->table('categories')
            ->where('id', $this->firstCategoryId($this->tonho))
            ->update(['name' => self::XSS_NAME]);

        $this->actingAs($this->createUser($this->tonho))
            ->get('/admin/bar-do-tonho/items/create')
            ->assertOk()
            ->assertDontSee(self::XSS_NAME, escape: false);
    }

    public function test_snapshot_keeps_html_as_plain_json_text(): void
    {
        /*
         * O snapshot guarda o texto cru, sem escapar: escapar é trabalho de
         * quem renderiza (React escapa por padrão), e escapar aqui faria a mesa
         * ver "&lt;script&gt;". O que protege é o JSON ser só dado: servido
         * como application/json (SnapshotCacheHeadersTest) e a PWA proibida de
         * usar dangerouslySetInnerHTML (regra react/no-danger no oxlint).
         */
        $this->storeXssItem();
        Storage::fake(config('filesystems.snapshot_disk'));

        (new PublishMenu($this->tonho))->handle();

        $menu = json_decode(
            Storage::disk(config('filesystems.snapshot_disk'))->get(PublishMenu::versionPath('bar-do-tonho', 1)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $item = $menu['categories'][0]['items'][0];

        $this->assertSame(self::XSS_NAME, $item['name']);
        $this->assertSame(self::XSS_DESCRIPTION, $item['description']);
    }

    /** @param  array<string, mixed>  $overrides */
    private function itemData(array $overrides = []): array
    {
        return [
            'category_id' => $this->firstCategoryId($this->tonho),
            'name' => 'Pudim',
            'price_cents' => '12.50',
            'sort_order' => 1,
            ...$overrides,
        ];
    }

    private function itemExists(string $name): bool
    {
        return DB::connection('pgsql_owner')->table('items')->where('name', $name)->exists();
    }

    private function storeXssItem(): int
    {
        $itemId = $this->firstItemId($this->tonho);

        DB::connection('pgsql_owner')->table('items')->where('id', $itemId)
            ->update(['name' => self::XSS_NAME, 'description' => self::XSS_DESCRIPTION]);

        return $itemId;
    }
}
