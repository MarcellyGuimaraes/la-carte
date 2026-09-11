import type { Menu } from '../types/menu'

/**
 * Cardápio fixo de um bar fictício.
 *
 * Único ponto do app que conhece dados concretos. Quando o endpoint Laravel
 * existir, este arquivo sai e um `fetch` entra no lugar, sem tocar em nenhum
 * componente — o contrato em `types/menu.ts` é o mesmo dos dois lados.
 */
export const menuFixture: Menu = {
  tenant: {
    id: 1,
    name: 'Bar do Tonho',
    slug: 'bar-do-tonho',
  },
  generated_at: '2026-09-11T12:00:00Z',
  categories: [
    {
      id: 1,
      name: 'Bebidas',
      sort_order: 1,
      items: [
        {
          id: 101,
          name: 'Chopp Pilsen 300ml',
          description: 'Tirado na hora, colarinho de dois dedos.',
          price_cents: 1200,
          image_url: null,
          featured: true,
          available: true,
          sort_order: 1,
        },
        {
          id: 102,
          name: 'Caipirinha de limão',
          description: 'Cachaça artesanal, limão taiti e açúcar.',
          price_cents: 2200,
          image_url: null,
          featured: false,
          available: true,
          sort_order: 2,
        },
        {
          id: 103,
          name: 'Refrigerante lata',
          description: null,
          price_cents: 700,
          image_url: null,
          featured: false,
          available: true,
          sort_order: 3,
        },
        {
          id: 104,
          name: 'Suco de laranja 500ml',
          description: 'Natural, sem açúcar.',
          price_cents: 1400,
          image_url: null,
          featured: false,
          available: false,
          sort_order: 4,
        },
      ],
    },
    {
      id: 2,
      name: 'Petiscos',
      sort_order: 2,
      items: [
        {
          id: 201,
          name: 'Porção de calabresa acebolada',
          description: 'Serve 2 pessoas, acompanha pão de alho.',
          price_cents: 4800,
          image_url: null,
          featured: true,
          available: true,
          sort_order: 1,
        },
        {
          id: 202,
          name: 'Bolinho de bacalhau (8 un.)',
          description: 'Com maionese de limão siciliano.',
          price_cents: 5200,
          image_url: null,
          featured: false,
          available: true,
          sort_order: 2,
        },
        {
          id: 203,
          name: 'Batata frita rústica',
          description: 'Com alecrim e parmesão ralado na hora.',
          price_cents: 3600,
          image_url: null,
          featured: false,
          available: true,
          sort_order: 3,
        },
      ],
    },
    {
      id: 3,
      name: 'Pratos',
      sort_order: 3,
      items: [
        {
          id: 301,
          name: 'Filé à parmegiana',
          description: 'Arroz, fritas e salada. Serve 2 pessoas.',
          price_cents: 8900,
          image_url: null,
          featured: true,
          available: true,
          sort_order: 1,
        },
        {
          id: 302,
          name: 'Feijoada individual',
          description: 'Só aos sábados. Couve, farofa, laranja e arroz.',
          price_cents: 5900,
          image_url: null,
          featured: false,
          available: false,
          sort_order: 2,
        },
        {
          id: 303,
          name: 'Frango à passarinho com mandioca',
          description: null,
          price_cents: 6200,
          image_url: null,
          featured: false,
          available: true,
          sort_order: 3,
        },
      ],
    },
  ],
}
