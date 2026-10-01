import type { Branding, Theme } from '../types/menu'

/**
 * Whitelabel: transforma a marca do snapshot no que o CSS precisa.
 *
 * Funções puras; quem aplica no documento é useBranding (App.tsx). As paletas
 * em si vivem em index.css, por [data-theme]: aqui só sai a cor da marca e o
 * texto que vai em cima dela.
 */

/** Marca de quem ainda não personalizou, e de snapshots anteriores ao whitelabel. */
export const DEFAULT_BRANDING: Branding = { theme: 'dark', brand_color: null, logo_url: null }

/**
 * Fundo de cada tema, para a <meta name="theme-color"> (barra do navegador).
 * Precisa bater com --bg de index.css.
 */
export const THEME_BACKGROUND: Record<Theme, string> = {
  dark: '#14110f',
  light: '#faf7f2',
}

const DARK_TEXT = '#14110f'
const LIGHT_TEXT = '#ffffff'

/** Luminância relativa da WCAG, de 0 (preto) a 1 (branco). */
function luminance(hex: string): number {
  const channel = (offset: number) => {
    const value = parseInt(hex.slice(offset, offset + 2), 16) / 255
    return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
  }

  return 0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5)
}

function contrast(a: string, b: string): number {
  const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (light + 0.05) / (dark + 0.05)
}

/**
 * Texto legível sobre a cor da marca (selo "destaque", botões). O dono escolhe
 * qualquer cor; amarelo pede texto escuro, azul-marinho pede branco.
 */
export function textOn(hex: string): string {
  return contrast(hex, DARK_TEXT) >= contrast(hex, LIGHT_TEXT) ? DARK_TEXT : LIGHT_TEXT
}

/**
 * Variáveis CSS que sobrescrevem a paleta do tema. Sem cor de marca, nenhuma:
 * vale o --accent padrão de cada tema.
 */
export function brandingVariables(branding: Branding): Record<string, string> {
  if (branding.brand_color === null) return {}

  return {
    '--accent': branding.brand_color,
    '--on-accent': textOn(branding.brand_color),
  }
}
