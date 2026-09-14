import type { Menu } from '../types/menu'

/** Um cardápio válido segundo o contrato, com o nome marcando a versão. */
export function menuFixture(label = 'v1'): Menu {
  return {
    tenant: { id: 1, name: `Bar do Tonho ${label}`, slug: 'bar-do-tonho' },
    generated_at: '2026-09-13T18:00:00Z',
    categories: [
      {
        id: 1,
        name: 'Bebidas',
        sort_order: 1,
        items: [
          {
            id: 1,
            name: 'Chopp',
            description: null,
            price_cents: 1200,
            image_url: null,
            featured: true,
            available: true,
            sort_order: 1,
          },
        ],
      },
    ],
  }
}
