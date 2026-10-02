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

/** Paletas prontas da PWA (index.css). Espelha o enum Theme do painel. */
export type Theme = 'dark' | 'light'

/**
 * Whitelabel: a marca do restaurante, publicada junto com o cardápio.
 * Gerada em PublishMenu::snapshot() (painel/app/Jobs/PublishMenu.php).
 */
export type Branding = {
  theme: Theme
  /** Cor PRIMÁRIA (o nome ficou da 1ª versão). `#rrggbb` minúsculo. `null` = cor padrão do tema. */
  brand_color: string | null
  /** Cor secundária: selo de destaque e preço. `null` = usa a primária. */
  secondary_color: string | null
  /** URL absoluta do WebP do logo no CDN. `null` = sem logo. */
  logo_url: string | null
  /** URL absoluta do WebP da capa (banner do topo). `null` = sem capa. */
  cover_url: string | null
  /** Frase curta abaixo do nome, até 140 caracteres. `null` = sem slogan. */
  tagline: string | null
}

/**
 * Branding como pode chegar no snapshot: os da primeira versão do whitelabel
 * (ainda em cache nos celulares e no storage) não têm capa, slogan nem cor
 * secundária. `normalizeBranding` (lib/branding.ts) completa o que falta.
 */
type LaterBrandingFields = 'cover_url' | 'tagline' | 'secondary_color'
export type PublishedBranding = Omit<Branding, LaterBrandingFields> & Partial<Pick<Branding, LaterBrandingFields>>

/**
 * Contatos do rodapé. Já normalizados no painel e conferidos pelo banco; a
 * PWA confere de novo (menu-guard) porque eles viram URL.
 */
export type Contacts = {
  /** Só dígitos, com DDI: `5511999998888`. Vira https://wa.me/<isto>. */
  whatsapp: string | null
  /** Usuário sem @, minúsculo. Vira https://instagram.com/<isto>. */
  instagram: string | null
  /** Texto livre; vira busca no Google Maps. */
  address: string | null
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
  /**
   * Ausente nos snapshots publicados antes do whitelabel, que continuam no
   * cache dos celulares: sem ela, vale a marca padrão.
   */
  branding?: PublishedBranding
  /** Ausente nos snapshots anteriores aos contatos: sem ela, rodapé sem links. */
  contacts?: Contacts
  categories: Category[]
  /** Quando este snapshot foi gerado. Usado para exibir "atualizado em". */
  generated_at: string
}
