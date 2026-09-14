<?php

namespace Tests\Feature\Resilience;

use App\Filament\Plataforma\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Items\Pages\CreateItem;
use App\Http\Middleware\UsePlatformConnection;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\InteractsWithPanels;
use Tests\Concerns\WithTwoTenants;
use Tests\TestCase;

/**
 * O que o dono lê quando algo dá errado: mensagem clara em português, e
 * nunca detalhe interno (SQL, caminho de arquivo, stack trace).
 */
#[Group('resilience')]
class ErrorMessagesTest extends TestCase
{
    use InteractsWithPanels, WithTwoTenants;

    /** Sinais de mensagem crua: inglês do Laravel ou chave sem tradução. O Filament põe o label em minúscula. */
    private const UNTRANSLATED = ['The ', ' field', 'validation.', 'must be', 'is required'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTwoTenants();
        Storage::fake('public');
    }

    public function test_item_form_errors_are_clear_portuguese_messages(): void
    {
        $this->bootAdminPanelAs($this->tonho);

        $errors = Livewire::test(CreateItem::class)
            ->fillForm([
                'category_id' => null,
                'name' => '',
                'price_cents' => '100000.00',
                'sort_order' => 'abc',
                'image_path' => UploadedFile::fake()->create('cardapio.pdf', 100, 'application/pdf'),
            ])
            ->call('create')
            ->errors();

        $this->assertSame('O campo categoria é obrigatório.', $errors->first('data.category_id'));
        $this->assertSame('O campo nome é obrigatório.', $errors->first('data.name'));
        $this->assertSame('O campo preço não pode ser maior que 99999.99.', $errors->first('data.price_cents'));
        /* "abc" falha primeiro em numeric; "1.5" cairia em integer. */
        $this->assertSame('O campo ordem deve ser um número.', $errors->first('data.sort_order'));
        $this->assertStringStartsWith('O campo foto deve ser um arquivo do tipo', $errors->first('data.image_path'));
        $this->assertNoRawMessages($errors->all());
    }

    public function test_negative_price_and_unreadable_image_messages_are_clear(): void
    {
        $this->bootAdminPanelAs($this->tonho);

        $errors = Livewire::test(CreateItem::class)
            ->fillForm([
                'category_id' => $this->firstCategoryId($this->tonho),
                'name' => 'Pudim',
                'price_cents' => '-1',
                'sort_order' => 1,
                'image_path' => UploadedFile::fake()->createWithContent('foto.jpg', 'isto não é imagem'),
            ])
            ->call('create')
            ->errors();

        $this->assertSame('O campo preço deve ser pelo menos 0.', $errors->first('data.price_cents'));
        $this->assertSame('O campo foto precisa ser uma imagem válida.', $errors->first('data.image_path'));
        $this->assertNoRawMessages($errors->all());
    }

    public function test_category_form_errors_are_clear_portuguese_messages(): void
    {
        $this->bootAdminPanelAs($this->tonho);

        $errors = Livewire::test(CreateCategory::class)
            ->fillForm(['name' => '', 'sort_order' => '99999999999'])
            ->call('create')
            ->errors();

        $this->assertSame('O campo nome é obrigatório.', $errors->first('data.name'));
        $this->assertSame('O campo ordem não pode ser maior que 1000000.', $errors->first('data.sort_order'));
        $this->assertNoRawMessages($errors->all());
    }

    public function test_onboarding_errors_are_clear_portuguese_messages(): void
    {
        $this->actingAs($this->createSuperAdmin());
        Filament::setCurrentPanel('plataforma');
        Filament::bootCurrentPanel();
        DB::setDefaultConnection(UsePlatformConnection::CONNECTION);

        $errors = Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Outro Tonho',
                'slug' => 'bar-do-tonho',
                'plan' => 'free',
                'active' => true,
                'owner_name' => 'Zé',
                'owner_email' => 'nao-e-email',
                'owner_password' => '123',
            ])
            ->call('create')
            ->errors();

        DB::setDefaultConnection('pgsql');

        $this->assertSame('Este valor de endereço (slug) já está em uso.', $errors->first('data.slug'));
        $this->assertStringContainsString('e-mail válido', (string) $errors->first('data.owner_email'));
        $this->assertStringStartsWith('O campo', (string) $errors->first('data.owner_password'));
        $this->assertNoRawMessages($errors->all());
    }

    /* ---- Erro inesperado em produção (APP_DEBUG=false) ---- */

    public function test_unexpected_error_page_hides_internals(): void
    {
        config(['app.debug' => false]);
        Route::get('/_resiliencia/boom', fn () => throw $this->internalError());

        $this->get('/_resiliencia/boom')
            ->assertStatus(500)
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('coluna_secreta')
            ->assertDontSee(base_path(), escape: false)
            ->assertDontSee('vendor/laravel', escape: false)
            ->assertDontSee('Stack trace');
    }

    public function test_unexpected_error_in_json_or_livewire_request_hides_internals(): void
    {
        config(['app.debug' => false]);
        Route::post('/_resiliencia/boom', fn () => throw $this->internalError());

        $response = $this->withHeaders(['X-Livewire' => 'true'])
            ->postJson('/_resiliencia/boom')
            ->assertStatus(500);

        $this->assertSame(['message' => 'Server Error'], $response->json());
    }

    public function test_runtime_error_message_from_code_is_not_shown_to_the_user(): void
    {
        config(['app.debug' => false]);
        Route::get('/_resiliencia/runtime', fn () => throw new RuntimeException('Falha ao gravar [menus/bar-do-tonho/v9.json] no storage de snapshots.'));

        $this->get('/_resiliencia/runtime')
            ->assertStatus(500)
            ->assertDontSee('menus/bar-do-tonho')
            ->assertDontSee('storage de snapshots');
    }

    private function internalError(): QueryException
    {
        return new QueryException(
            'pgsql',
            'select coluna_secreta from items where tenant_id = ?',
            [1],
            new \PDOException('SQLSTATE[42703]: Undefined column: coluna_secreta'),
        );
    }

    /** @param  list<string>  $messages */
    private function assertNoRawMessages(array $messages): void
    {
        $this->assertNotEmpty($messages);

        foreach ($messages as $message) {
            foreach (self::UNTRANSLATED as $raw) {
                $this->assertStringNotContainsString($raw, $message, "mensagem crua: {$message}");
            }
        }
    }
}
