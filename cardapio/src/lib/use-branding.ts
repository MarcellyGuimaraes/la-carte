import { useEffect } from 'react'
import type { Branding } from '../types/menu'
import { brandBackground, brandingVariables } from './branding'

/** O título neutro do index.html: é para onde o cleanup volta. */
const DEFAULT_TITLE = 'Cardápio'

/**
 * Veste o documento com a marca do restaurante.
 *
 * No <html> e no <head>, não num wrapper: o fundo do body, a barra do
 * navegador (theme-color), o título da aba e o favicon ficam fora da árvore
 * do React.
 *
 * O HTML servido é neutro ("Cardápio"), igual para todos os restaurantes; a
 * marca só entra aqui, depois que o snapshot carregou. Assim nenhum
 * restaurante aparece com o nome ou o ícone de outro.
 *
 * Roda de novo quando chega versão nova do cardápio com outra marca; o
 * cleanup tira tudo o que pôs, para "voltar à cor padrão" funcionar.
 */
export function useBranding({ theme, brand_color, secondary_color, logo_url }: Branding, tenantName: string): void {
  useEffect(() => {
    const root = document.documentElement
    const variables = brandingVariables({ theme, brand_color, secondary_color })
    const themeColor = document.querySelector('meta[name="theme-color"]')
    const favicon = document.querySelector<HTMLLinkElement>('link[rel="icon"]')
    /* O ícone padrão (index.html), para devolver no cleanup. */
    const defaultFavicon = favicon && { href: favicon.getAttribute('href') ?? '', type: favicon.type }

    root.dataset.theme = theme
    for (const [name, value] of Object.entries(variables)) {
      root.style.setProperty(name, value)
    }
    themeColor?.setAttribute('content', brandBackground({ theme, brand_color }))
    document.title = `${tenantName} — ${DEFAULT_TITLE}`

    /* O type do link é o do SVG padrão; o logo é WebP. */
    if (favicon && logo_url) {
      favicon.href = logo_url
      favicon.type = 'image/webp'
    }

    return () => {
      for (const name of Object.keys(variables)) {
        root.style.removeProperty(name)
      }
      document.title = DEFAULT_TITLE
      if (favicon && defaultFavicon && logo_url) {
        favicon.setAttribute('href', defaultFavicon.href)
        favicon.type = defaultFavicon.type
      }
    }
  }, [theme, brand_color, secondary_color, logo_url, tenantName])
}
