import { useEffect, useRef, useState } from 'react'
import type { Menu } from '../types/menu'
import { knownVersion, readPointer, readVersion, rememberVersion } from './menu-source'

/**
 * Orquestra de onde vem o cardápio que está na tela.
 *
 * 1. Abre na hora a última versão que este aparelho já abriu (do cache do
 *    service worker, sem esperar rede). Em internet ruim ou offline, é isto.
 * 2. Em paralelo, pergunta ao ponteiro qual versão está no ar. Se for outra,
 *    baixa e troca. Sem rede, fica com a que já está na tela.
 * 3. Pergunta de novo quando a conexão volta e quando o app volta a ficar
 *    visível (celular que ficou no bolso com a aba aberta).
 */

export type MenuState =
  | { status: 'loading' }
  | { status: 'ready'; menu: Menu; version: number }
  | { status: 'error'; error: string }

export function useMenu(slug: string): MenuState {
  const [state, setState] = useState<MenuState>({ status: 'loading' })
  /* Ref, não state: os listeners precisam da versão atual, não a da montagem. */
  const shownVersion = useRef<number | null>(null)

  useEffect(() => {
    let cancelled = false

    const show = (menu: Menu, version: number) => {
      if (cancelled) return
      shownVersion.current = version
      rememberVersion(slug, version)
      setState({ status: 'ready', menu, version })
    }

    const fail = (error: string) => {
      /* Erro só substitui a tela se não há cardápio nenhum nela. */
      if (!cancelled && shownVersion.current === null) setState({ status: 'error', error })
    }

    const syncWithPointer = async () => {
      const pointer = await readPointer(slug)
      if (!pointer.ok) return fail(pointer.error)
      if (pointer.value === shownVersion.current) return

      const menu = await readVersion(slug, pointer.value)
      if (!menu.ok) return fail(menu.error)

      show(menu.value, pointer.value)
    }

    const start = async () => {
      const known = knownVersion(slug)

      if (known !== null) {
        const cached = await readVersion(slug, known)
        if (cached.ok) show(cached.value, known)
      }

      await syncWithPointer()
    }

    /*
     * Na primeira visita o service worker assume a página DEPOIS de o cardápio
     * já ter sido baixado, então aquele download não passou por ele e não foi
     * cacheado. Pedir de novo quando ele assume guarda a versão para o offline.
     */
    const warmCache = () => {
      if (shownVersion.current !== null) void readVersion(slug, shownVersion.current)
    }

    const syncWhenVisible = () => {
      if (document.visibilityState === 'visible') void syncWithPointer()
    }

    void start()
    window.addEventListener('online', syncWithPointer)
    document.addEventListener('visibilitychange', syncWhenVisible)
    navigator.serviceWorker?.addEventListener('controllerchange', warmCache)

    return () => {
      cancelled = true
      window.removeEventListener('online', syncWithPointer)
      document.removeEventListener('visibilitychange', syncWhenVisible)
      navigator.serviceWorker?.removeEventListener('controllerchange', warmCache)
    }
  }, [slug])

  return state
}
