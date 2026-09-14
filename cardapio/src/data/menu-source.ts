import type { Menu } from '../types/menu'
import { isMenu } from '../lib/menu-guard'

/**
 * Fronteira de dados: ÚNICO arquivo que sabe de onde o cardápio vem.
 *
 * O painel publica dois arquivos por restaurante no object storage (CDN):
 *
 *   menus/{slug}/current.json   { "version": n }   Cache-Control: no-store
 *   menus/{slug}/v{n}.json      o Menu             Cache-Control: immutable
 *
 * O ponteiro diz QUAL versão está no ar; a versão é o conteúdo. Como um v{n}
 * nunca muda, o service worker o guarda para sempre (CacheFirst) e é ele que
 * abre o cardápio offline. O ponteiro nunca é cacheado: é só "tem novidade?".
 */

/** Sem barra no fim. Dev: MinIO do docker-compose. Produção: domínio do CDN. */
const SNAPSHOT_BASE_URL = import.meta.env.VITE_SNAPSHOT_BASE_URL

/* Erro de configuração quebra alto, em vez de buscar "undefined/menus/...". */
if (!SNAPSHOT_BASE_URL) {
  throw new Error('VITE_SNAPSHOT_BASE_URL não definida. Veja cardapio/.env.example.')
}

/**
 * Rede ruim pendura requisições por muito tempo. Passado isso, desiste e fica
 * com o que já está na tela (ou mostra o erro, se não há nada).
 */
const NETWORK_TIMEOUT_MS = 8000

/**
 * Erro como valor previsto, não exceção. O TypeScript obriga quem consome a
 * tratar a falha antes de acessar o dado.
 */
export type Result<T> = { ok: true; value: T } | { ok: false; error: string }

const OFFLINE_ERROR = 'Sem conexão e sem cardápio salvo. Conecte-se uma vez para usar offline.'

export function pointerUrl(slug: string): string {
  return `${SNAPSHOT_BASE_URL}/menus/${slug}/current.json`
}

export function versionUrl(slug: string, version: number): string {
  return `${SNAPSHOT_BASE_URL}/menus/${slug}/v${version}.json`
}

/**
 * Qual versão está publicada agora. Sempre da rede: o service worker não tem
 * rota para este arquivo, e `cache: 'no-store'` também pula o cache HTTP do
 * navegador, caso algum CDN ignore o header.
 */
export async function readPointer(slug: string): Promise<Result<number>> {
  const response = await fetchWithTimeout(pointerUrl(slug), { cache: 'no-store' })

  if (!response.ok) return response

  if (response.value.status === 404) {
    return { ok: false, error: 'Cardápio não encontrado. Confira o QR code da mesa.' }
  }

  if (!response.value.ok) {
    return { ok: false, error: 'Não foi possível carregar o cardápio.' }
  }

  const body: unknown = await response.value.json().catch(() => null)
  const version = (body as { version?: unknown } | null)?.version

  if (typeof version !== 'number' || !Number.isInteger(version) || version < 1) {
    return { ok: false, error: 'Cardápio publicado com formato inválido.' }
  }

  return { ok: true, value: version }
}

/**
 * O conteúdo de uma versão. Passa pelo service worker (CacheFirst): se já foi
 * baixada alguma vez, responde do aparelho, com ou sem rede.
 */
export async function readVersion(slug: string, version: number): Promise<Result<Menu>> {
  const response = await fetchWithTimeout(versionUrl(slug, version))

  if (!response.ok) return response

  if (!response.value.ok) {
    return { ok: false, error: 'Não foi possível carregar o cardápio.' }
  }

  const body: unknown = await response.value.json().catch(() => null)

  /* JSON válido não basta: fora do contrato derrubaria a tela (tela branca). */
  if (!isMenu(body)) {
    return { ok: false, error: 'Cardápio publicado com formato inválido.' }
  }

  return { ok: true, value: body }
}

/** Mesmo nome do cacheName da rota de v{n}.json em vite.config.ts. */
const VERSIONS_CACHE = 'menu-versions'

/**
 * Versões deste restaurante que o service worker guardou no aparelho, da mais
 * nova para a mais antiga.
 *
 * Plano B do offline: se a versão lembrada saiu do cache (limite de entradas,
 * limpeza do navegador) ou o localStorage foi apagado, ainda dá para abrir
 * qualquer outra que esteja guardada, em vez de "sem cardápio salvo".
 */
export async function cachedVersions(slug: string): Promise<number[]> {
  try {
    if (typeof caches === 'undefined') return []

    const requests = await (await caches.open(VERSIONS_CACHE)).keys()
    const pattern = new RegExp(`/menus/${slug}/v(\\d+)\\.json$`)

    return requests
      .map((request) => pattern.exec(new URL(request.url).pathname)?.[1])
      .filter((match): match is string => match !== undefined)
      .map(Number)
      .sort((a, b) => b - a)
  } catch {
    /* Cache Storage indisponível (contexto inseguro, dados bloqueados). */
    return []
  }
}

/**
 * Última versão que este aparelho CONSEGUIU abrir. Só é gravada depois de o
 * v{n}.json carregar, ou seja, quando ele já está no cache do service worker.
 * É o que permite abrir offline sem perguntar nada à rede.
 *
 * localStorage pode lançar (aba anônima, dados bloqueados): nesse caso o app
 * funciona igual, só sem abrir offline.
 */
export function knownVersion(slug: string): number | null {
  try {
    const stored = Number(localStorage.getItem(storageKey(slug)))
    return Number.isInteger(stored) && stored > 0 ? stored : null
  } catch {
    return null
  }
}

export function rememberVersion(slug: string, version: number): void {
  try {
    localStorage.setItem(storageKey(slug), String(version))
  } catch {
    /* Sem armazenamento: segue sem memória de versão. */
  }
}

function storageKey(slug: string): string {
  return `menu-version:${slug}`
}

async function fetchWithTimeout(url: string, init: RequestInit = {}): Promise<Result<Response>> {
  try {
    return {
      ok: true,
      value: await fetch(url, { ...init, signal: AbortSignal.timeout(NETWORK_TIMEOUT_MS) }),
    }
  } catch {
    /* Sem rede, timeout ou CORS: para quem está na mesa é tudo "sem conexão". */
    return { ok: false, error: OFFLINE_ERROR }
  }
}
