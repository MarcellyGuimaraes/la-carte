<?php

namespace Tests\Feature\Resilience;

use App\Jobs\ProcessItemImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\WithTwoTenants;
use Tests\Support\FaultyDisk;
use Tests\TestCase;

/**
 * Job de imagem sob falha: nunca deixa o item com URL para um arquivo que
 * não existe, e sempre dá para reprocessar.
 */
#[Group('resilience')]
class ItemImageResilienceTest extends TestCase
{
    use WithTwoTenants;

    private FaultyDisk $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTwoTenants();
        /* Original e WebP vivem no mesmo disco em dev. */
        $this->disk = FaultyDisk::replace('public');
    }

    public function test_storage_down_fails_the_job_and_leaves_no_url(): void
    {
        $itemId = $this->itemWithPhoto($this->tonho, 'itens/1/pudim.jpg');
        $this->disk->failPutWhen(fn (string $path) => str_ends_with($path, '.webp'));

        $this->expectJobToFail(fn () => $this->runJob($this->tonho, $itemId, 'itens/1/pudim.jpg'));

        $this->assertNull($this->imageUrl($itemId));
    }

    public function test_partial_failure_never_records_url_for_a_missing_size(): void
    {
        $itemId = $this->itemWithPhoto($this->tonho, 'itens/1/pudim.jpg');
        /* 400 grava, 800 (o da URL) falha. */
        $this->disk->failPutWhen(fn (string $path) => str_ends_with($path, '-800.webp'));

        $this->expectJobToFail(fn () => $this->runJob($this->tonho, $itemId, 'itens/1/pudim.jpg'));

        $this->assertNull($this->imageUrl($itemId));
    }

    public function test_retrying_after_storage_comes_back_records_the_url(): void
    {
        $itemId = $this->itemWithPhoto($this->tonho, 'itens/1/pudim.jpg');
        $this->disk->failPutWhen(fn (string $path) => str_ends_with($path, '.webp'));
        $this->expectJobToFail(fn () => $this->runJob($this->tonho, $itemId, 'itens/1/pudim.jpg'));

        $this->disk->heal();
        $this->runJob($this->tonho, $itemId, 'itens/1/pudim.jpg');

        $this->assertSame($this->disk->url('itens/1/pudim-800.webp'), $this->imageUrl($itemId));
        foreach (ProcessItemImage::WIDTHS as $width) {
            $this->disk->assertExists("itens/1/pudim-{$width}.webp");
        }
    }

    public function test_missing_original_fails_explicitly_and_leaves_no_url(): void
    {
        $itemId = $this->itemWithPhoto($this->tonho, 'itens/1/pudim.jpg');
        $this->disk->delete('itens/1/pudim.jpg');

        $this->expectJobToFail(fn () => $this->runJob($this->tonho, $itemId, 'itens/1/pudim.jpg'));

        $this->assertNull($this->imageUrl($itemId));
    }

    public function test_storage_failures_are_retried_by_the_queue(): void
    {
        $job = new ProcessItemImage($this->tonho, 1, 'x.jpg');

        /* Falha de storage costuma ser passageira: a fila tenta de novo, com espera. */
        $this->assertGreaterThan(1, $job->tries);
        $this->assertNotEmpty($job->backoff);
    }

    /* ---- Reprocesso ---- */

    public function test_reprocess_command_requeues_every_photo_without_webp_in_all_restaurants(): void
    {
        $tonhoPending = $this->itemWithPhoto($this->tonho, 'itens/1/a.jpg');
        $nonaPending = $this->itemWithPhoto($this->nona, 'itens/2/b.jpg');
        $done = $this->itemWithPhoto($this->tonho, 'itens/1/c.jpg', itemIndex: 1, url: 'http://x/c-800.webp');
        Queue::fake();

        $exit = Artisan::call('items:reprocess-images');

        $this->assertSame(0, $exit);
        Queue::assertPushed(ProcessItemImage::class, 2);
        Queue::assertPushed(ProcessItemImage::class, fn (ProcessItemImage $j) => $j->itemId === $tonhoPending && $j->tenantId === $this->tonho && $j->imagePath === 'itens/1/a.jpg');
        Queue::assertPushed(ProcessItemImage::class, fn (ProcessItemImage $j) => $j->itemId === $nonaPending && $j->tenantId === $this->nona);
        Queue::assertNotPushed(ProcessItemImage::class, fn (ProcessItemImage $j) => $j->itemId === $done);
    }

    public function test_reprocess_command_can_target_one_restaurant(): void
    {
        $this->itemWithPhoto($this->tonho, 'itens/1/a.jpg');
        $nonaPending = $this->itemWithPhoto($this->nona, 'itens/2/b.jpg');
        Queue::fake();

        Artisan::call('items:reprocess-images', ['--tenant' => 'pizzaria-da-nona']);

        Queue::assertPushed(ProcessItemImage::class, 1);
        Queue::assertPushed(ProcessItemImage::class, fn (ProcessItemImage $j) => $j->itemId === $nonaPending);
    }

    public function test_reprocess_command_fails_loudly_for_unknown_restaurant(): void
    {
        Queue::fake();

        $exit = Artisan::call('items:reprocess-images', ['--tenant' => 'nao-existe']);

        $this->assertNotSame(0, $exit);
        Queue::assertNothingPushed();
    }

    public function test_reprocess_end_to_end_fixes_an_item_whose_job_was_lost(): void
    {
        /* Foto gravada, job perdido (failed_jobs limpa, dispatch que falhou). */
        $itemId = $this->itemWithPhoto($this->tonho, 'itens/1/perdida.jpg');

        /* sync: o comando enfileira e o job roda na hora. */
        config(['queue.default' => 'sync']);
        Artisan::call('items:reprocess-images');

        $this->assertSame($this->disk->url('itens/1/perdida-800.webp'), $this->imageUrl($itemId));
    }

    private function runJob(int $tenantId, int $itemId, string $path): void
    {
        (new ProcessItemImage($tenantId, $itemId, $path))->handle();
    }

    private function expectJobToFail(\Closure $run): void
    {
        try {
            $run();
        } catch (RuntimeException) {
            return;
        }

        $this->fail('o job deveria ter falhado');
    }

    private function itemWithPhoto(int $tenantId, string $path, int $itemIndex = 0, ?string $url = null): int
    {
        $this->disk->put($path, UploadedFile::fake()->image('foto.jpg', 900, 600)->getContent());

        $itemId = DB::connection('pgsql_owner')->table('items')
            ->where('tenant_id', $tenantId)->orderBy('id')->skip($itemIndex)->value('id');

        DB::connection('pgsql_owner')->table('items')->where('id', $itemId)
            ->update(['image_path' => $path, 'image_url' => $url]);

        return $itemId;
    }

    private function imageUrl(int $itemId): ?string
    {
        return DB::connection('pgsql_owner')->table('items')->where('id', $itemId)->value('image_url');
    }
}
