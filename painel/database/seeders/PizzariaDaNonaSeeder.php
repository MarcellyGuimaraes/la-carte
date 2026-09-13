<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Segundo restaurante de teste, com dono próprio.
 *
 * Existe para conferir o isolamento na prática: logar como o Tonho e como a
 * Nona e ver cardápios diferentes. Idempotente como o BarDoTonhoSeeder.
 */
class PizzariaDaNonaSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::updateOrCreate(
            ['slug' => 'pizzaria-da-nona'],
            ['name' => 'Pizzaria da Nona', 'plan' => 'free', 'active' => true],
        );

        $tenant->users()->updateOrCreate(
            ['email' => 'nona@exemplo.com'],
            ['name' => 'Giovanna Bellini', 'password' => 'segredo123'],
        );

        foreach ($this->menu() as $categoryOrder => $categoryData) {
            $category = $tenant->categories()->updateOrCreate(
                ['name' => $categoryData['name']],
                ['sort_order' => $categoryOrder + 1],
            );

            foreach ($categoryData['items'] as $itemOrder => $item) {
                $category->items()->updateOrCreate(
                    ['name' => $item['name']],
                    $item + ['tenant_id' => $tenant->id, 'sort_order' => $itemOrder + 1],
                );
            }
        }
    }

    /**
     * Preços em centavos inteiros, como manda o contrato que a PWA consome.
     *
     * @return list<array{name: string, items: list<array<string, mixed>>}>
     */
    private function menu(): array
    {
        return [
            [
                'name' => 'Pizzas',
                'items' => [
                    [
                        'name' => 'Margherita',
                        'description' => 'Molho de tomate, muçarela de búfala e manjericão.',
                        'price_cents' => 5900,
                        'featured' => true,
                        'available' => true,
                    ],
                    [
                        'name' => 'Calabresa',
                        'description' => 'Calabresa fatiada, cebola roxa e azeitona preta.',
                        'price_cents' => 5400,
                        'featured' => false,
                        'available' => true,
                    ],
                    [
                        'name' => 'Quatro queijos',
                        'description' => 'Muçarela, gorgonzola, parmesão e provolone.',
                        'price_cents' => 6600,
                        'featured' => true,
                        'available' => true,
                    ],
                ],
            ],
            [
                'name' => 'Sobremesas',
                'items' => [
                    [
                        'name' => 'Pizza de chocolate com morango',
                        'description' => 'Broto. Chocolate ao leite e morangos frescos.',
                        'price_cents' => 3900,
                        'featured' => false,
                        'available' => true,
                    ],
                    [
                        'name' => 'Tiramisù',
                        'description' => 'Receita da família, feito no dia.',
                        'price_cents' => 2400,
                        'featured' => false,
                        'available' => false,
                    ],
                ],
            ],
        ];
    }
}
