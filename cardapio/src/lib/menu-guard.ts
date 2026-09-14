import type { Category, Menu, MenuItem, Tenant } from '../types/menu'

/**
 * Confere em tempo de execução que um JSON é mesmo um Menu.
 *
 * O TypeScript só confia no `as Menu`; um snapshot com JSON válido mas formato
 * errado (bug no gerador, arquivo trocado, resposta de erro do CDN) chegaria à
 * tela e derrubaria a renderização: tela branca na mesa. Validando aqui, vira
 * um erro previsto, e a PWA fica com a versão anterior.
 *
 * Funções puras, sem biblioteca: o contrato é pequeno e mudar aqui junto com
 * types/menu.ts é o esperado.
 */

type Unknown = Record<string, unknown>

const isObject = (value: unknown): value is Unknown =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

const isNullableString = (value: unknown): boolean => value === null || typeof value === 'string'

function isTenant(value: unknown): value is Tenant {
  return (
    isObject(value) &&
    typeof value.id === 'number' &&
    typeof value.name === 'string' &&
    typeof value.slug === 'string'
  )
}

function isItem(value: unknown): value is MenuItem {
  return (
    isObject(value) &&
    typeof value.id === 'number' &&
    typeof value.name === 'string' &&
    isNullableString(value.description) &&
    Number.isInteger(value.price_cents) &&
    isNullableString(value.image_url) &&
    typeof value.featured === 'boolean' &&
    typeof value.available === 'boolean' &&
    typeof value.sort_order === 'number'
  )
}

function isCategory(value: unknown): value is Category {
  return (
    isObject(value) &&
    typeof value.id === 'number' &&
    typeof value.name === 'string' &&
    typeof value.sort_order === 'number' &&
    Array.isArray(value.items) &&
    value.items.every(isItem)
  )
}

export function isMenu(value: unknown): value is Menu {
  return (
    isObject(value) &&
    isTenant(value.tenant) &&
    typeof value.generated_at === 'string' &&
    Array.isArray(value.categories) &&
    value.categories.every(isCategory)
  )
}
