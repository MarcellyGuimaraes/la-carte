import { describe, expect, it, vi } from 'vitest'
import { createMenuLoader, type MenuSource } from './menu-loader'
import type { Result } from './menu-source'
import type { Menu } from '../types/menu'
import { menuFixture } from '../test/fixtures'

/*
 * Cenários de rede ruim e offline, com a fonte de dados simulada:
 *
 *   network   o que o CDN responderia (null = sem rede)
 *   cache     versões guardadas pelo service worker neste aparelho
 *   known     última versão que o aparelho abriu (localStorage)
 *
 * readVersion segue a regra do service worker: CacheFirst. Está no cache,
 * responde sem rede; não está, precisa de rede.
 */
type World = {
  network: { pointer: number | 'malformado'; versions: Record<number, Menu | 'malformado'> } | null
  cache: Record<number, Menu>
  known: number | null
}

const OFFLINE: Result<never> = { ok: false, error: 'Sem conexão e sem cardápio salvo.' }
const BROKEN: Result<never> = { ok: false, error: 'Cardápio publicado com formato inválido.' }

function sourceFor(world: World): MenuSource & { remembered: number[] } {
  const remembered: number[] = []

  return {
    remembered,
    readPointer: vi.fn(async () => {
      if (world.network === null) return OFFLINE
      if (world.network.pointer === 'malformado') return BROKEN
      return { ok: true, value: world.network.pointer } as const
    }),
    readVersion: vi.fn(async (_slug: string, version: number) => {
      if (world.cache[version]) return { ok: true, value: world.cache[version] } as const
      if (world.network === null) return OFFLINE
      const menu = world.network.versions[version]
      if (menu === undefined) return { ok: false, error: 'Não foi possível carregar o cardápio.' } as const
      if (menu === 'malformado') return BROKEN
      world.cache[version] = menu
      return { ok: true, value: menu } as const
    }),
    knownVersion: () => world.known,
    rememberVersion: (_slug: string, version: number) => {
      world.known = version
      remembered.push(version)
    },
    cachedVersions: async () => Object.keys(world.cache).map(Number).sort((a, b) => b - a),
  }
}

async function run(world: World) {
  const shown: Array<{ name: string; version: number }> = []
  const errors: string[] = []
  const source = sourceFor(world)

  const loader = createMenuLoader('bar-do-tonho', source, {
    onMenu: (menu, version) => shown.push({ name: menu.tenant.name, version }),
    onError: (error) => errors.push(error),
  })
  await loader.start()

  return { loader, shown, errors, source, last: () => shown.at(-1) }
}

describe('abrir o cardápio', () => {
  it('primeira visita online mostra a versão publicada e a memoriza', async () => {
    const world: World = { network: { pointer: 3, versions: { 3: menuFixture('v3') } }, cache: {}, known: null }

    const { last, errors, source } = await run(world)

    expect(last()).toEqual({ name: 'Bar do Tonho v3', version: 3 })
    expect(errors).toEqual([])
    expect(source.remembered).toEqual([3])
  })

  it('SEM REDE: current.json inacessível abre a última versão do cache', async () => {
    const world: World = { network: null, cache: { 3: menuFixture('v3') }, known: 3 }

    const { last, errors } = await run(world)

    expect(last()).toEqual({ name: 'Bar do Tonho v3', version: 3 })
    expect(errors).toEqual([])
  })

  it('ponteiro aponta para versão fora do cache e ela não baixa: continua na que tem', async () => {
    /* Rede instável: o ponteiro respondeu v4, o download do v4 falhou. */
    const world: World = { network: { pointer: 4, versions: {} }, cache: { 3: menuFixture('v3') }, known: 3 }

    const { shown, errors } = await run(world)

    expect(shown).toEqual([{ name: 'Bar do Tonho v3', version: 3 }])
    expect(errors).toEqual([])
    expect(world.known).toBe(3)
  })

  it('versão nova malformada: continua na anterior e não memoriza a quebrada', async () => {
    const world: World = { network: { pointer: 4, versions: { 4: 'malformado' } }, cache: { 3: menuFixture('v3') }, known: 3 }

    const { shown, errors } = await run(world)

    expect(shown).toEqual([{ name: 'Bar do Tonho v3', version: 3 }])
    expect(errors).toEqual([])
    expect(world.known).toBe(3)
  })

  it('ponteiro malformado com cardápio salvo: continua no salvo', async () => {
    const world: World = { network: { pointer: 'malformado', versions: {} }, cache: { 3: menuFixture('v3') }, known: 3 }

    const { last, errors } = await run(world)

    expect(last()?.version).toBe(3)
    expect(errors).toEqual([])
  })

  it('SEM REDE e a versão lembrada saiu do cache: cai para outra versão guardada', async () => {
    const world: World = { network: null, cache: { 2: menuFixture('v2') }, known: 3 }

    const { last, errors } = await run(world)

    expect(last()).toEqual({ name: 'Bar do Tonho v2', version: 2 })
    expect(errors).toEqual([])
  })

  it('SEM REDE e sem memória (localStorage limpo), mas com cache: abre a mais nova guardada', async () => {
    const world: World = { network: null, cache: { 1: menuFixture('v1'), 2: menuFixture('v2') }, known: null }

    const { last } = await run(world)

    expect(last()?.version).toBe(2)
  })

  it('SEM REDE e nada guardado: mensagem de erro, nunca silêncio (tela branca)', async () => {
    const world: World = { network: null, cache: {}, known: null }

    const { shown, errors } = await run(world)

    expect(shown).toEqual([])
    expect(errors).toEqual([OFFLINE.ok ? '' : OFFLINE.error])
  })

  it('primeira visita com versão malformada: mensagem de erro', async () => {
    const world: World = { network: { pointer: 1, versions: { 1: 'malformado' } }, cache: {}, known: null }

    const { shown, errors } = await run(world)

    expect(shown).toEqual([])
    expect(errors).toHaveLength(1)
  })

  it('versão nova disponível: troca e memoriza', async () => {
    const world: World = { network: { pointer: 4, versions: { 4: menuFixture('v4') } }, cache: { 3: menuFixture('v3') }, known: 3 }

    const { shown, source } = await run(world)

    expect(shown.map((s) => s.version)).toEqual([3, 4])
    expect(source.remembered).toContain(4)
  })

  it('ponteiro igual à versão na tela: não baixa nada de novo', async () => {
    const world: World = { network: { pointer: 3, versions: {} }, cache: { 3: menuFixture('v3') }, known: 3 }

    const { shown, source } = await run(world)

    expect(shown).toHaveLength(1)
    expect(source.readVersion).toHaveBeenCalledTimes(1)
  })
})

describe('voltar a ter rede', () => {
  it('abriu offline, a rede volta com versão nova: sync troca para a nova', async () => {
    const world: World = { network: null, cache: { 3: menuFixture('v3') }, known: 3 }
    const { loader, last } = await run(world)

    world.network = { pointer: 5, versions: { 5: menuFixture('v5') } }
    await loader.sync()

    expect(last()).toEqual({ name: 'Bar do Tonho v5', version: 5 })
    expect(world.known).toBe(5)
  })

  it('erro de sync depois de algo na tela não substitui o cardápio por mensagem', async () => {
    const world: World = { network: { pointer: 3, versions: {} }, cache: { 3: menuFixture('v3') }, known: 3 }
    const { loader, errors } = await run(world)

    world.network = null
    await loader.sync()

    expect(errors).toEqual([])
  })

  it('depois de stop(), respostas atrasadas não mexem na tela', async () => {
    const world: World = { network: null, cache: {}, known: null }
    const { loader, shown, errors } = await run(world)

    loader.stop()
    world.network = { pointer: 1, versions: { 1: menuFixture() } }
    await loader.sync()

    expect(shown).toEqual([])
    expect(errors).toHaveLength(1)
  })
})
