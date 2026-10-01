import { useEffect } from 'react'
import type { Branding } from '../types/menu'
import { THEME_BACKGROUND, brandingVariables } from './branding'

/**
 * Veste o documento com a marca do restaurante.
 *
 * No <html>, não num wrapper: o fundo do body e a barra do navegador
 * (theme-color) também precisam mudar, e ficam fora da árvore do React.
 *
 * Roda de novo quando chega versão nova do cardápio com outra marca; o
 * cleanup tira a cor anterior, para "voltar à cor padrão" funcionar.
 */
export function useBranding({ theme, brand_color, logo_url }: Branding): void {
  useEffect(() => {
    const root = document.documentElement
    const variables = brandingVariables({ theme, brand_color, logo_url })

    root.dataset.theme = theme
    for (const [name, value] of Object.entries(variables)) {
      root.style.setProperty(name, value)
    }
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', THEME_BACKGROUND[theme])

    return () => {
      for (const name of Object.keys(variables)) {
        root.style.removeProperty(name)
      }
    }
  }, [theme, brand_color, logo_url])
}
