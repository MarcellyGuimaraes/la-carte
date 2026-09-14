<?php

namespace App\Console\Commands;

use App\Http\Middleware\UsePlatformConnection;
use App\Jobs\ProcessItemImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reenfileira a conversão das fotos que ficaram sem WebP.
 *
 * Existe para o caso em que o queue:retry não resolve: o dispatch falhou, ou
 * a failed_jobs foi limpa. O critério é o estado do banco (tem image_path e
 * não tem image_url), não o histórico da fila.
 *
 * Idempotente: rodar duas vezes só enfileira de novo, e o job ignora foto que
 * mudou ou já foi convertida.
 */
class ReprocessItemImages extends Command
{
    protected $signature = 'items:reprocess-images
                            {--tenant= : Slug de um restaurante; sem isto, todos}';

    protected $description = 'Reenfileira a conversão para WebP das fotos de itens que ficaram sem image_url';

    public function handle(): int
    {
        /*
         * Tarefa de operação da plataforma, que atravessa restaurantes: lê pela
         * conexão com BYPASSRLS (a mesma do /plataforma), só para listar ids.
         * Quem converte é o job, que roda preso à RLS do tenant de cada item.
         */
        $platform = DB::connection(UsePlatformConnection::CONNECTION);

        $query = $platform->table('items')
            ->join('tenants', 'tenants.id', '=', 'items.tenant_id')
            ->whereNotNull('items.image_path')
            ->whereNull('items.image_url')
            ->orderBy('items.id')
            ->select('items.id', 'items.tenant_id', 'items.image_path');

        if ($slug = $this->option('tenant')) {
            if (! $platform->table('tenants')->where('slug', $slug)->exists()) {
                $this->error("Restaurante [{$slug}] não existe.");

                return self::FAILURE;
            }

            $query->where('tenants.slug', $slug);
        }

        $count = 0;

        foreach ($query->cursor() as $item) {
            ProcessItemImage::dispatch($item->tenant_id, $item->id, $item->image_path);
            $count++;
        }

        $this->info("{$count} foto(s) reenfileirada(s). Precisa do worker rodando.");

        return self::SUCCESS;
    }
}
