<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Um restaurante de teste com cardápio realista.
 *
 * Idempotente: roda quantas vezes quiser sem duplicar nada, porque tudo é
 * localizado por uma chave estável (slug do tenant, nome da categoria, nome
 * do item) antes de ser criado ou atualizado.
 */
class BarDoTonhoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::updateOrCreate(
            ['slug' => 'bar-do-tonho'],
            ['name' => 'Bar do Tonho', 'plan' => 'free', 'active' => true],
        );

        $tenant->users()->updateOrCreate(
            ['email' => 'tonho@exemplo.com'],
            ['name' => 'Antônio Ribeiro', 'password' => 'segredo123'],
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
     * O cardápio em si, separado da lógica de gravação.
     *
     * Preços em centavos inteiros, como manda o contrato que a PWA consome.
     *
     * @return list<array{name: string, items: list<array<string, mixed>>}>
     */
    private function menu(): array
    {
        return [
            [
                'name' => 'Bebidas',
                'items' => [
                    [
                        'name' => 'Chopp Pilsen 300ml',
                        'description' => 'Tirado na hora, colarinho de dois dedos.',
                        'price_cents' => 1200,
                        'featured' => true,
                        'available' => true,
                    ],
                    [
                        'name' => 'Chopp Pilsen 500ml',
                        'description' => 'A tulipa grande, para quem veio ficar.',
                        'price_cents' => 1800,
                        'featured' => false,
                        'available' => true,
                    ],
                    [
                        'name' => 'Caipirinha de limão',
                        'description' => 'Cachaça artesanal, limão taiti e açúcar.',
                        'price_cents' => 2200,
                        'featured' => true,
                        'available' => true,
                    ],
                    [
                        'name' => 'Caipirinha de maracujá',
                        'description' => 'Polpa fresca batida na hora.',
                        'price_cents' => 2400,
                        'featured' => false,
                        'available' => true,
                    ],
                    [
                        'name' => 'Refrigerante lata 350ml',
                        'description' => 'Coca-Cola, Guaraná ou Soda.',
                        'price_cents' => 700,
                        'featured' => false,
                        'available' => true,
                    ],
                    [
                        'name' => 'Suco de laranja 500ml',
                        'description' => 'Natural, sem açúcar.',
                        'price_cents' => 1400,
                        'featured' => false,
                        'available' => false,
                    ],
                    [
                        'name' => 'Água mineral 500ml',
                        'description' => null,
                        'price_cents' => 500,
                        'featured' => false,
                        'available' => true,
                    ],
                ],
            ],
            [
                'name' => 'Petiscos',
                'items' => [
                    [
                        'name' => 'Porção de calabresa acebolada',
                        'description' => 'Serve 2 pessoas, acompanha pão de alho.',
                        'price_cents' => 4800,
                        'featured' => true,
                        'available' => true,
                    ],
                    [
                        'name' => 'Bolinho de bacalhau (8 un.)',
                        'description' => 'Com maionese de limão siciliano.',
                        'price_cents' => 5200,
                        'featured' => false,
                        'available' => true,
                    ],
                    [
                        'name' => 'Batata frita rústica',
                        'description' => 'Com alecrim e parmesão ralado na hora.',
                        'price_cents' => 3600,
                        'featured' => false,
                        'available' => true,
                    ],
                    [
                        'name' => 'Torresmo de rolo',
                        'description' => 'Crocante por fora, macio por dentro. Acompanha melaço.',
                        'price_cents' => 4200,
                        'featured' => true,
                        'available' => true,
                    ],
                    [
                        'name' => 'Isca de tilápia',
                        'description' => 'Empanada na farinha de milho, com molho tártaro.',
                        'price_cents' => 5800,
                        'featured' => false,
                        'available' => true,
                    ],
                    [
                        'name' => 'Pastel de feira (4 un.)',
                        'description' => 'Carne, queijo ou palmito.',
                        'price_cents' => 2800,
                        'featured' => false,
                        'available' => true,
                    ],
                ],
            ],
            [
                'name' => 'Pratos',
                'items' => [
                    [
                        'name' => 'Filé à parmegiana',
                        'description' => 'Arroz, fritas e salada. Serve 2 pessoas.',
                        'price_cents' => 8900,
                        'featured' => true,
                        'available' => true,
                    ],
                    [
                        'name' => 'Feijoada individual',
                        'description' => 'Só aos sábados. Couve, farofa, laranja e arroz.',
                        'price_cents' => 5900,
                        'featured' => false,
                        'available' => false,
                    ],
                    [
                        'name' => 'Frango à passarinho com mandioca',
                        'description' => 'No alho e óleo, com mandioca frita.',
                        'price_cents' => 6200,
                        'featured' => false,
                        'available' => true,
                    ],
                    [
                        'name' => 'Picanha na chapa',
                        'description' => 'Com farofa, vinagrete e pão de alho. Serve 2 pessoas.',
                        'price_cents' => 12900,
                        'featured' => true,
                        'available' => true,
                    ],
                    [
                        'name' => 'Tilápia grelhada',
                        'description' => 'Com arroz de brócolis e legumes na manteiga.',
                        'price_cents' => 7400,
                        'featured' => false,
                        'available' => true,
                    ],
                ],
            ],
        ];
    }
}
