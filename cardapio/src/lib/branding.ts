import type { Branding, PublishedBranding, Theme } from '../types/menu'

/**
 * Whitelabel: transforma a marca do snapshot no que o CSS precisa.
 *
 * Funções puras; quem aplica no documento é useBranding (App.tsx). As paletas
 * base vivem em index.css, por [data-theme]. Com cor de marca, a paleta inteira
 * é DERIVADA dela aqui: o dono escolhe uma cor e o sistema garante a leitura.
 * Por isso não há "cor de fundo" para o dono escolher: cardápio ilegível na
 * mesa é o pior defeito possível do produto.
 */

/** Marca de quem ainda não personalizou, e de snapshots anteriores ao whitelabel. */
const DEFAULT_BRANDING: Branding = {
  theme: 'dark',
  brand_color: null,
  secondary_color: null,
  logo_url: null,
  cover_url: null,
  tagline: null,
}

/**
 * Completa a marca como chegou no snapshot: sem `branding` (antes do
 * whitelabel) vale o padrão; sem capa/slogan/secundária (primeira versão), null.
 */
export function normalizeBranding(branding: PublishedBranding | undefined): Branding {
  if (branding === undefined) return DEFAULT_BRANDING

  return {
    ...branding,
    secondary_color: branding.secondary_color ?? null,
    cover_url: branding.cover_url ?? null,
    tagline: branding.tagline ?? null,
  }
}

/**
 * Paleta base de cada tema. Precisa bater com index.css (:root e
 * [data-theme='light']): é a partir dela que a marca tinge as superfícies.
 */
const THEME_BASE: Record<Theme, { bg: string; surface: string; surfaceRaised: string; border: string }> = {
  dark: { bg: '#14110f', surface: '#1f1b18', surfaceRaised: '#2a2420', border: '#332c26' },
  light: { bg: '#faf7f2', surface: '#ffffff', surfaceRaised: '#f0ebe4', border: '#e4ddd4' },
}

/**
 * Quanto da cor da marca entra em cada superfície. Pouco de propósito: o
 * fundo ganha o "clima" da marca sem competir com o conteúdo nem derrubar o
 * contraste do texto.
 */
const TINT = { bg: 0.04, surface: 0.07, surfaceRaised: 0.1, border: 0.14 }

/** Contraste mínimo da WCAG (AA) para texto normal. */
const MIN_TEXT_CONTRAST = 4.5

const DARK_TEXT = '#14110f'
const LIGHT_TEXT = '#ffffff'

type Rgb = [number, number, number]

function toRgb(hex: string): Rgb {
  return [1, 3, 5].map((offset) => parseInt(hex.slice(offset, offset + 2), 16)) as Rgb
}

function toHex(rgb: Rgb): string {
  return `#${rgb.map((channel) => Math.round(channel).toString(16).padStart(2, '0')).join('')}`
}

/** Mistura `amount` (0 a 1) de `color` em `base`. */
export function mix(base: string, color: string, amount: number): string {
  const [a, b] = [toRgb(base), toRgb(color)]
  return toHex(a.map((channel, i) => channel + (b[i] - channel) * amount) as Rgb)
}

/** Luminância relativa da WCAG, de 0 (preto) a 1 (branco). */
function luminance(hex: string): number {
  const [r, g, b] = toRgb(hex).map((value) => {
    const channel = value / 255
    return channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
  })

  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

export function contrast(a: string, b: string): number {
  const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (light + 0.05) / (dark + 0.05)
}

type Hsl = [number, number, number]

function toHsl(hex: string): Hsl {
  const [r, g, b] = toRgb(hex).map((value) => value / 255)
  const max = Math.max(r, g, b)
  const min = Math.min(r, g, b)
  const lightness = (max + min) / 2

  if (max === min) return [0, 0, lightness]

  const delta = max - min
  const saturation = lightness > 0.5 ? delta / (2 - max - min) : delta / (max + min)
  const hue =
    max === r ? (g - b) / delta + (g < b ? 6 : 0) : max === g ? (b - r) / delta + 2 : (r - g) / delta + 4

  return [hue / 6, saturation, lightness]
}

function fromHsl([hue, saturation, lightness]: Hsl): string {
  if (saturation === 0) return toHex([lightness * 255, lightness * 255, lightness * 255])

  const q = lightness < 0.5 ? lightness * (1 + saturation) : lightness + saturation - lightness * saturation
  const p = 2 * lightness - q
  const channel = (t: number) => {
    const x = t < 0 ? t + 1 : t > 1 ? t - 1 : t
    if (x < 1 / 6) return p + (q - p) * 6 * x
    if (x < 1 / 2) return q
    if (x < 2 / 3) return p + (q - p) * (2 / 3 - x) * 6
    return p
  }

  return toHex([channel(hue + 1 / 3) * 255, channel(hue) * 255, channel(hue - 1 / 3) * 255])
}

/**
 * A cor da marca como TEXTO sobre os fundos onde ela aparece (página e
 * superfície dos itens): mesmo matiz, luminosidade ajustada em passos até
 * passar de 4,5:1 em todos. Amarelo no tema claro escurece;
 * azul-marinho no escuro clareia. Cor que já passa volta intacta.
 *
 * Sempre termina: no limite vira preto (fundo claro) ou branco (fundo
 * escuro), que passam com folga sobre os fundos dos temas.
 */
export function readableOn(color: string, backgrounds: readonly string[]): string {
  const [hue, saturation, start] = toHsl(color)
  const step = luminance(backgrounds[0]) > 0.5 ? -0.02 : 0.02

  for (let lightness = start; lightness >= 0 && lightness <= 1; lightness += step) {
    const candidate = fromHsl([hue, saturation, lightness])
    if (backgrounds.every((bg) => contrast(candidate, bg) >= MIN_TEXT_CONTRAST)) return candidate
  }

  return step < 0 ? '#000000' : '#ffffff'
}

/**
 * Texto legível sobre a cor da marca (selo "destaque"). O dono escolhe
 * qualquer cor; amarelo pede texto escuro, azul-marinho pede branco.
 */
export function textOn(hex: string): string {
  return contrast(hex, DARK_TEXT) >= contrast(hex, LIGHT_TEXT) ? DARK_TEXT : LIGHT_TEXT
}

/** Fundo da página com a marca aplicada: o mesmo do CSS e da barra do navegador. */
export function brandBackground({ theme, brand_color }: Pick<Branding, 'theme' | 'brand_color'>): string {
  const base = THEME_BASE[theme].bg
  return brand_color === null ? base : mix(base, brand_color, TINT.bg)
}

type Colors = Pick<Branding, 'theme' | 'brand_color' | 'secondary_color'>

/** As três variáveis de uma cor: exata (superfície), de texto (legível) e texto em cima dela. */
function colorRole(prefix: string, color: string, backgrounds: readonly string[]): Record<string, string> {
  return {
    [`--${prefix}`]: color,
    [`--${prefix}-text`]: readableOn(color, backgrounds),
    [`--on-${prefix}`]: textOn(color),
  }
}

/**
 * Variáveis CSS que sobrescrevem a paleta do tema. Sem nenhuma cor, nenhuma:
 * vale a paleta padrão de cada tema (index.css).
 *
 * Primária (--accent*): a cor da casa. Tinge fundo, superfícies e borda e
 * pinta categorias e a barra.
 * Secundária (--secondary*): a cor de chamada. Selo de destaque e preço.
 * Sem secundária, ela herda a primária (visual igual ao de uma cor só).
 *
 * Em cada papel: --x é a cor exata (superfícies), --x-text a ajustada para
 * leitura (textos) e --on-x o texto em cima de --x.
 */
export function brandingVariables({ theme, brand_color, secondary_color }: Colors): Record<string, string> {
  if (brand_color === null && secondary_color === null) return {}

  const base = THEME_BASE[theme]
  const bg = brandBackground({ theme, brand_color })
  const surface = brand_color === null ? base.surface : mix(base.surface, brand_color, TINT.surface)
  const secondary = secondary_color ?? brand_color

  return {
    ...(brand_color !== null && {
      '--bg': bg,
      '--surface': surface,
      '--surface-raised': mix(base.surfaceRaised, brand_color, TINT.surfaceRaised),
      '--border': mix(base.border, brand_color, TINT.border),
      ...colorRole('accent', brand_color, [bg, surface]),
    }),
    /* Nunca null aqui: pelo menos uma das duas existe (checado acima). */
    ...colorRole('secondary', secondary as string, [bg, surface]),
  }
}
