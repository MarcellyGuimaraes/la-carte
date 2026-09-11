import type { Menu } from '../types/menu'
import { menuFixture } from './menu-fixture'

/**
 * Fronteira de dados: ÚNICO arquivo que sabe de onde o cardápio vem.
 *
 * Hoje devolve o módulo fixo. No passo 7 do roteiro, o corpo de `loadMenu`
 * vira um fetch ao endpoint Laravel e a assinatura continua a mesma, então
 * nenhum componente muda:
 *
 *   const response = await fetch(`/api/menu/${slug}.json`)
 *   if (!response.ok) return { ok: false, error: 'Cardápio indisponível.' }
 *   return { ok: true, menu: (await response.json()) as Menu }
 */

/**
 * Erro como valor previsto, não exceção. O TypeScript obriga quem consome a
 * tratar a falha antes de acessar o cardápio.
 */
export type MenuResult =
  | { ok: true; menu: Menu }
  | { ok: false; error: string }

export async function loadMenu(): Promise<MenuResult> {
  return { ok: true, menu: menuFixture }
}
