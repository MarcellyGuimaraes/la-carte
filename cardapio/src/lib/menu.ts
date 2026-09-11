/**
 * Helpers puros sobre o cardápio.
 *
 * O backend já devolverá tudo ordenado, mas ordenar aqui custa nada e deixa a
 * apresentação independente da boa vontade de quem serializa o JSON.
 */

type HasOrder = { sort_order: number }

export function byOrder<T extends HasOrder>(list: readonly T[]): T[] {
  return [...list].sort((a, b) => a.sort_order - b.sort_order)
}
