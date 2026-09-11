/**
 * Formatação de dinheiro. Puro, sem estado.
 *
 * Preços trafegam em centavos inteiros para evitar erro de ponto flutuante
 * (0.1 + 0.2 !== 0.3). A conversão para reais acontece só na hora de exibir.
 */

const brl = new Intl.NumberFormat('pt-BR', {
  style: 'currency',
  currency: 'BRL',
})

export function formatPrice(cents: number): string {
  return brl.format(cents / 100)
}
