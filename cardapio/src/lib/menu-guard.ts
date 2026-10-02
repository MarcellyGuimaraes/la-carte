import type { Category, Contacts, Menu, MenuItem, PublishedBranding, Tenant } from '../types/menu'

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

const HEX_COLOR = /^#[0-9a-f]{6}$/

/** Ausente vale: snapshots da primeira versão do whitelabel não têm o campo. */
const isOptionalNullableString = (value: unknown): boolean => value === undefined || isNullableString(value)

/*
 * Cor fora do formato não chega ao CSS: o snapshot inteiro é recusado, como
 * qualquer outro campo errado, e a mesa fica com a versão anterior.
 */
const isNullableMatch = (value: unknown, pattern: RegExp): boolean =>
  value === null || (typeof value === 'string' && pattern.test(value))

function isBranding(value: unknown): value is PublishedBranding {
  return (
    isObject(value) &&
    (value.theme === 'dark' || value.theme === 'light') &&
    isNullableMatch(value.brand_color, HEX_COLOR) &&
    (value.secondary_color === undefined || isNullableMatch(value.secondary_color, HEX_COLOR)) &&
    isNullableString(value.logo_url) &&
    isOptionalNullableString(value.cover_url) &&
    isOptionalNullableString(value.tagline)
  )
}

/*
 * Mesmos formatos dos CHECKs do banco. WhatsApp e Instagram entram em URL:
 * fora do formato, o snapshot é recusado em vez de virar link estranho.
 */
const WHATSAPP = /^[0-9]{12,13}$/
const INSTAGRAM = /^[a-z0-9._]{1,30}$/

function isContacts(value: unknown): value is Contacts {
  return (
    isObject(value) &&
    isNullableMatch(value.whatsapp, WHATSAPP) &&
    isNullableMatch(value.instagram, INSTAGRAM) &&
    isNullableString(value.address)
  )
}

export function isMenu(value: unknown): value is Menu {
  return (
    isObject(value) &&
    isTenant(value.tenant) &&
    /* Opcionais: snapshots de antes do whitelabel/dos contatos não têm, e seguem válidos. */
    (value.branding === undefined || isBranding(value.branding)) &&
    (value.contacts === undefined || isContacts(value.contacts)) &&
    typeof value.generated_at === 'string' &&
    Array.isArray(value.categories) &&
    value.categories.every(isCategory)
  )
}
