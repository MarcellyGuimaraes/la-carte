import type { Menu } from '../types/menu'
import type { Result } from './menu-source'

/**
 * Decide qual cardápio vai para a tela, com rede boa, ruim ou nenhuma.
 *
 * Sem React e sem fetch aqui dentro: a fonte de dados entra por parâmetro.
 * Assim os cenários de falha (sem rede, versão fora do cache, snapshot
 * malformado) são testáveis com uma fonte simulada, e o hook useMenu só liga
 * isto aos eventos do navegador.
 *
 * Regras:
 * 1. Abre do aparelho primeiro: a última versão lembrada e, se ela não estiver
 *    mais guardada, qualquer outra versão em cache. A tela não espera rede.
 * 2. Depois pergunta ao ponteiro. Versão nova que baixa e é válida: troca.
 * 3. Qualquer falha com algo já na tela é silenciosa: fica com o que tem.
 *    Erro só aparece quando não há cardápio nenhum para mostrar.
 */

export type MenuSource = {
  readPointer(slug: string): Promise<Result<number>>
  readVersion(slug: string, version: number): Promise<Result<Menu>>
  knownVersion(slug: string): number | null
  rememberVersion(slug: string, version: number): void
  cachedVersions(slug: string): Promise<number[]>
}

export type MenuEvents = {
  onMenu(menu: Menu, version: number): void
  onError(error: string): void
}

export type MenuLoader = {
  start(): Promise<void>
  sync(): Promise<void>
  shownVersion(): number | null
  stop(): void
}

export function createMenuLoader(slug: string, source: MenuSource, events: MenuEvents): MenuLoader {
  let shown: number | null = null
  let stopped = false

  const show = (menu: Menu, version: number) => {
    if (stopped) return
    shown = version
    /* Só depois de carregar: nunca lembra de versão que não abriu. */
    source.rememberVersion(slug, version)
    events.onMenu(menu, version)
  }

  const fail = (error: string) => {
    if (!stopped && shown === null) events.onError(error)
  }

  const openFromDevice = async () => {
    const known = source.knownVersion(slug)
    const candidates = [...new Set([known, ...(await source.cachedVersions(slug))])].filter(
      (version): version is number => version !== null,
    )

    for (const version of candidates) {
      const cached = await source.readVersion(slug, version)
      if (cached.ok) return show(cached.value, version)
    }
  }

  const sync = async () => {
    const pointer = await source.readPointer(slug)
    if (!pointer.ok) return fail(pointer.error)
    if (pointer.value === shown) return

    const menu = await source.readVersion(slug, pointer.value)
    if (!menu.ok) return fail(menu.error)

    show(menu.value, pointer.value)
  }

  return {
    start: async () => {
      await openFromDevice()
      await sync()
    },
    sync,
    shownVersion: () => shown,
    stop: () => {
      stopped = true
    },
  }
}
