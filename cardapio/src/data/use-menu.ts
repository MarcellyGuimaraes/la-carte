import { useEffect, useState } from 'react'
import type { Menu } from '../types/menu'
import { createMenuLoader } from './menu-loader'
import { cachedVersions, knownVersion, readPointer, readVersion, rememberVersion } from './menu-source'

/**
 * Liga o menu-loader (as regras de rede e offline) ao React e aos eventos do
 * navegador. As decisões vivem em menu-loader.ts, testadas sem navegador.
 *
 * Checa o ponteiro de novo quando a conexão volta e quando o app volta a
 * ficar visível (celular que ficou no bolso com a aba aberta).
 */

export type MenuState =
  | { status: 'loading' }
  | { status: 'ready'; menu: Menu; version: number }
  | { status: 'error'; error: string }

const source = { readPointer, readVersion, knownVersion, rememberVersion, cachedVersions }

export function useMenu(slug: string): MenuState {
  const [state, setState] = useState<MenuState>({ status: 'loading' })

  useEffect(() => {
    const loader = createMenuLoader(slug, source, {
      onMenu: (menu, version) => setState({ status: 'ready', menu, version }),
      onError: (error) => setState({ status: 'error', error }),
    })

    /*
     * Na primeira visita o service worker assume a página DEPOIS de o cardápio
     * já ter sido baixado, então aquele download não passou por ele e não foi
     * cacheado. Pedir de novo quando ele assume guarda a versão para o offline.
     */
    const warmCache = () => {
      const version = loader.shownVersion()
      if (version !== null) void readVersion(slug, version)
    }

    const sync = () => void loader.sync()
    const syncWhenVisible = () => {
      if (document.visibilityState === 'visible') sync()
    }

    void loader.start()
    window.addEventListener('online', sync)
    document.addEventListener('visibilitychange', syncWhenVisible)
    navigator.serviceWorker?.addEventListener('controllerchange', warmCache)

    return () => {
      loader.stop()
      window.removeEventListener('online', sync)
      document.removeEventListener('visibilitychange', syncWhenVisible)
      navigator.serviceWorker?.removeEventListener('controllerchange', warmCache)
    }
  }, [slug])

  return state
}
