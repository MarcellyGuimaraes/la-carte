/**
 * Contrato de dados do cardápio.
 *
 * Estes tipos descrevem EXATAMENTE o JSON do snapshot v{n}.json que o job
 * PublishMenu do painel grava no object storage (servido via CDN). Mudou aqui,
 * muda lá (painel/app/Jobs/PublishMenu.php), e vice-versa.
 *
 * Convenções, alinhadas com o que o Eloquent serializa por padrão:
 * - chaves em snake_case;
 * - dinheiro em centavos inteiros, nunca float;
 * - datas em ISO 8601 (UTC).
 */

export type Tenant = {
  id: number
  name: string
  slug: string
}

export type MenuItem = {
  id: number
  name: string
  /** Ausente quando o dono não escreveu descrição. */
  description: string | null
  /** Centavos. 1250 = R$ 12,50. */
  price_cents: number
  /** URL absoluta do WebP no CDN. `null` enquanto não há imagem. */
  image_url: string | null
  featured: boolean
  /** `false` = item existe no cardápio mas acabou hoje. */
  available: boolean
  sort_order: number
}

export type Category = {
  id: number
  name: string
  sort_order: number
  /** Itens já aninhados: o snapshot é lido, nunca consultado por join. */
  items: MenuItem[]
}

export type Menu = {
  tenant: Tenant
  categories: Category[]
  /** Quando este snapshot foi gerado. Usado para exibir "atualizado em". */
  generated_at: string
}
