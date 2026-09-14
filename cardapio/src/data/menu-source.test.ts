import { describe, expect, it, vi } from 'vitest'
import { cachedVersions, readPointer, readVersion } from './menu-source'
import { menuFixture } from '../test/fixtures'

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })

describe('readPointer', () => {
  it('lê a versão publicada sem usar cache HTTP', async () => {
    const fetchMock = vi.fn().mockResolvedValue(json({ version: 7 }))
    vi.stubGlobal('fetch', fetchMock)

    expect(await readPointer('bar-do-tonho')).toEqual({ ok: true, value: 7 })
    expect(fetchMock).toHaveBeenCalledWith(
      'https://cdn.test/la-carte/menus/bar-do-tonho/current.json',
      expect.objectContaining({ cache: 'no-store' }),
    )
  })

  it('sem rede vira erro previsto, não exceção', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))

    const result = await readPointer('bar-do-tonho')

    expect(result.ok).toBe(false)
    expect(!result.ok && result.error).toMatch(/Sem conexão/)
  })

  it('404 diz que o cardápio não existe', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('', { status: 404 })))

    const result = await readPointer('nao-existe')

    expect(!result.ok && result.error).toMatch(/não encontrado/)
  })

  it.each([
    ['corpo que não é JSON', new Response('<html>erro do CDN</html>', { status: 200 })],
    ['versão ausente', json({})],
    ['versão em texto', json({ version: '3' })],
    ['versão zero', json({ version: 0 })],
  ])('rejeita ponteiro com %s', async (_, response) => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response))

    const result = await readPointer('bar-do-tonho')

    expect(!result.ok && result.error).toMatch(/formato inválido/)
  })
})

describe('readVersion', () => {
  it('devolve o cardápio válido', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(json(menuFixture())))

    expect(await readVersion('bar-do-tonho', 3)).toEqual({ ok: true, value: menuFixture() })
  })

  it('JSON válido fora do contrato é erro, não cardápio quebrado na tela', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(json({ tenant: { id: 1 } })))

    const result = await readVersion('bar-do-tonho', 3)

    expect(!result.ok && result.error).toMatch(/formato inválido/)
  })

  it('arquivo truncado é erro', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{"tenant":{"id":1,"na', { status: 200 })))

    expect((await readVersion('bar-do-tonho', 3)).ok).toBe(false)
  })

  it('sem rede e fora do cache vira erro previsto', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))

    expect((await readVersion('bar-do-tonho', 3)).ok).toBe(false)
  })
})

describe('cachedVersions', () => {
  const stubCache = (urls: string[]) =>
    vi.stubGlobal('caches', {
      open: vi.fn().mockResolvedValue({ keys: vi.fn().mockResolvedValue(urls.map((url) => new Request(url))) }),
    })

  it('lista só as versões deste restaurante, da mais nova para a mais antiga', async () => {
    stubCache([
      'https://cdn.test/la-carte/menus/bar-do-tonho/v2.json',
      'https://cdn.test/la-carte/menus/bar-do-tonho/v10.json',
      'https://cdn.test/la-carte/menus/pizzaria-da-nona/v99.json',
      'https://cdn.test/la-carte/menus/bar-do-tonho/current.json',
    ])

    expect(await cachedVersions('bar-do-tonho')).toEqual([10, 2])
  })

  it('sem Cache Storage (navegador antigo, http sem SW) devolve vazio', async () => {
    vi.stubGlobal('caches', undefined)

    expect(await cachedVersions('bar-do-tonho')).toEqual([])
  })

  it('Cache Storage que lança devolve vazio', async () => {
    vi.stubGlobal('caches', { open: vi.fn().mockRejectedValue(new Error('SecurityError')) })

    expect(await cachedVersions('bar-do-tonho')).toEqual([])
  })
})
