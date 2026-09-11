import type { Menu } from '../types/menu'

/**
 * Fronteira de dados: ÚNICO arquivo que sabe de onde o cardápio vem.
 *
 * Hoje busca um JSON estático servido pelo próprio site. No passo 7 do roteiro
 * só a URL muda, para o endpoint do Laravel servido pelo CDN. O service worker
 * intercepta esta requisição e responde do cache (stale-while-revalidate),
 * então em rede ruim ou offline a tela não espera pela rede.
 */

/** Enquanto não há backend, o slug do restaurante é fixo. */
const MENU_URL = `${import.meta.env.BASE_URL}menu/bar-do-tonho.json`

/**
 * Erro como valor previsto, não exceção. O TypeScript obriga quem consome a
 * tratar a falha antes de acessar o cardápio.
 */
export type MenuResult =
  | { ok: true; menu: Menu }
  | { ok: false; error: string }

export async function loadMenu(): Promise<MenuResult> {
  try {
    const response = await fetch(MENU_URL)

    if (!response.ok) {
      return { ok: false, error: 'Não foi possível carregar o cardápio.' }
    }

    return { ok: true, menu: (await response.json()) as Menu }
  } catch {
    /* Primeira visita sem rede: não há nada em cache para o service worker servir. */
    return {
      ok: false,
      error: 'Sem conexão e sem cardápio salvo. Conecte-se uma vez para usar offline.',
    }
  }
}
