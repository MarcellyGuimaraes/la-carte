<?php

namespace Tests\Feature;

use App\Filament\Resources\Items\Pages\CreateItem;
use App\Jobs\ProcessItemImage;
use App\Models\Item;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\WithTwoTenants;
use Tests\TestCase;

/**
 * Pipeline de imagem: upload no /admin -> job na fila -> WebP em 3 larguras
 * -> URL final no item.
 *
 * O job roda aqui chamando handle() direto, pela conexão da aplicação (presa à
 * RLS) e SEM tenant definido antes: é a situação real do worker.
 */
class ItemImagePipelineTest extends TestCase
{
    use WithTwoTenants;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTwoTenants();
        Storage::fake('public');
    }

    public function test_uploading_a_photo_in_the_panel_queues_the_job(): void
    {
        Queue::fake();
        $this->bootPanelAs($this->tonho);

        Livewire::test(CreateItem::class)
            ->fillForm([
                'category_id' => $this->firstCategoryId($this->tonho),
                'name' => 'Pudim',
                'price_cents' => '12.50',
                'image_path' => UploadedFile::fake()->image('pudim.jpg', 1600, 1200),
                'sort_order' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = $this->ownerItem('Pudim');
        /* Sem worker ainda: original gravado, URL final ainda vazia. */
        $this->assertNull($item->image_url);

        Queue::assertPushed(ProcessItemImage::class, fn (ProcessItemImage $job): bool => $job->tenantId === $this->tonho
            && $job->itemId === $item->id
            && $job->imagePath === $item->image_path);
    }

    public function test_saving_without_changing_the_photo_does_not_queue_again(): void
    {
        $itemId = $this->itemWithPhoto($this->tonho, 'itens/1/pudim.jpg', 1600, 1200);
        Queue::fake();
        $this->actAsTenant($this->tonho);

        Item::findOrFail($itemId)->update(['name' => 'Pudim de leite']);

        Queue::assertNothingPushed();
    }

    public function test_job_generates_webp_in_three_widths_and_records_the_url(): void
    {
        $itemId = $this->itemWithPhoto($this->tonho, 'itens/1/pudim.jpg', 1600, 1200);

        (new ProcessItemImage($this->tonho, $itemId, 'itens/1/pudim.jpg'))->handle();

        foreach ([400 => 300, 800 => 600, 1200 => 900] as $width => $height) {
            $path = "itens/1/pudim-{$width}.webp";
            Storage::disk('public')->assertExists($path);

            $size = getimagesizefromstring(Storage::disk('public')->get($path));
            $this->assertSame('image/webp', $size['mime'], "{$path} não é WebP");
            $this->assertSame([$width, $height], [$size[0], $size[1]], "{$path} com tamanho errado");
        }

        $this->assertSame(
            Storage::disk('public')->url('itens/1/pudim-800.webp'),
            DB::connection('pgsql_owner')->table('items')->where('id', $itemId)->value('image_url'),
        );
    }

    public function test_job_does_not_leave_a_tenant_behind_for_the_next_job(): void
    {
        $itemId = $this->itemWithPhoto($this->tonho, 'itens/1/pudim.jpg', 500, 500);

        (new ProcessItemImage($this->tonho, $itemId, 'itens/1/pudim.jpg'))->handle();

        $this->assertSame('', DB::selectOne("select current_setting('app.current_tenant', true) as t")->t);
    }

    public function test_small_photo_is_converted_but_not_enlarged(): void
    {
        $itemId = $this->itemWithPhoto($this->tonho, 'itens/1/mini.png', 300, 200);

        (new ProcessItemImage($this->tonho, $itemId, 'itens/1/mini.png'))->handle();

        foreach (ProcessItemImage::WIDTHS as $width) {
            $size = getimagesizefromstring(Storage::disk('public')->get("itens/1/mini-{$width}.webp"));
            $this->assertSame([300, 200], [$size[0], $size[1]]);
        }
    }

    public function test_stale_job_does_not_overwrite_a_newer_photo(): void
    {
        /* O dono trocou para nova.jpg; o job da foto antiga chega depois. */
        $itemId = $this->itemWithPhoto($this->tonho, 'itens/1/nova.jpg', 800, 600);
        Storage::disk('public')->put('itens/1/antiga.jpg', $this->jpeg(800, 600));

        (new ProcessItemImage($this->tonho, $itemId, 'itens/1/antiga.jpg'))->handle();

        Storage::disk('public')->assertMissing('itens/1/antiga-800.webp');
        $this->assertNull(DB::connection('pgsql_owner')->table('items')->where('id', $itemId)->value('image_url'));
    }

    public function test_job_with_wrong_tenant_cannot_touch_another_restaurant_item(): void
    {
        /* Item do Tonho, job dizendo ser da Nona: a RLS esconde o item. */
        $itemId = $this->itemWithPhoto($this->tonho, 'itens/1/pudim.jpg', 800, 600);

        (new ProcessItemImage($this->nona, $itemId, 'itens/1/pudim.jpg'))->handle();

        $this->assertNull(DB::connection('pgsql_owner')->table('items')->where('id', $itemId)->value('image_url'));
    }

    public function test_unreadable_file_fails_without_retry(): void
    {
        $itemId = $this->itemWithPhoto($this->tonho, 'itens/1/falso.jpg', 10, 10);
        Storage::disk('public')->put('itens/1/falso.jpg', 'isto não é uma imagem');

        $job = (new ProcessItemImage($this->tonho, $itemId, 'itens/1/falso.jpg'))->withFakeQueueInteractions();
        $job->handle();

        $job->assertFailed();
        $this->assertNull(DB::connection('pgsql_owner')->table('items')->where('id', $itemId)->value('image_url'));
    }

    /**
     * Item com foto já gravada, criado pela conexão do dono: não passa pelos
     * eventos do model, então nenhum job é disparado aqui.
     */
    private function itemWithPhoto(int $tenantId, string $path, int $width, int $height): int
    {
        Storage::disk('public')->put($path, $this->jpeg($width, $height));

        $itemId = $this->firstItemId($tenantId);
        DB::connection('pgsql_owner')->table('items')->where('id', $itemId)
            ->update(['image_path' => $path, 'image_url' => null]);

        return $itemId;
    }

    private function jpeg(int $width, int $height): string
    {
        return UploadedFile::fake()->image('foto.jpg', $width, $height)->getContent();
    }

    private function ownerItem(string $name): object
    {
        return DB::connection('pgsql_owner')->table('items')->where('name', $name)->first();
    }

    /** Mesmo preparo do AdminPanelTenancyTest: Livewire::test pula os middlewares. */
    private function bootPanelAs(int $tenantId): void
    {
        $this->actAsTenant($tenantId);
        $this->actingAs($this->createUser($tenantId));
        Filament::setCurrentPanel('admin');
        Filament::setTenant(Tenant::findOrFail($tenantId));
        Filament::bootCurrentPanel();
    }
}
