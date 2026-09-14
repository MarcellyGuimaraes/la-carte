import { describe, expect, it } from 'vitest'
import { isMenu } from './menu-guard'
import { menuFixture } from '../test/fixtures'

/*
 * Snapshot com JSON válido mas formato errado não pode chegar à tela: um
 * `categories` ausente derruba a renderização (tela branca).
 */
describe('isMenu', () => {
  it('aceita um cardápio no contrato', () => {
    expect(isMenu(menuFixture())).toBe(true)
  })

  it('aceita descrição e imagem nulas ou preenchidas', () => {
    const menu = menuFixture()
    menu.categories[0].items[0].description = 'Gelado'
    menu.categories[0].items[0].image_url = 'https://cdn.test/x-800.webp'

    expect(isMenu(menu)).toBe(true)
  })

  it.each([
    ['null', null],
    ['texto', 'oi'],
    ['objeto vazio', {}],
    ['sem categories', { ...menuFixture(), categories: undefined }],
    ['categories não é lista', { ...menuFixture(), categories: {} }],
    ['sem tenant', { ...menuFixture(), tenant: null }],
    ['slug não é texto', { ...menuFixture(), tenant: { id: 1, name: 'x', slug: 2 } }],
    ['sem generated_at', { ...menuFixture(), generated_at: undefined }],
  ])('rejeita %s', (_, value) => {
    expect(isMenu(value)).toBe(false)
  })

  it.each([
    ['preço em texto', { price_cents: '12.00' }],
    ['preço fracionado', { price_cents: 12.5 }],
    ['sem nome', { name: undefined }],
    ['available ausente', { available: undefined }],
    ['imagem numérica', { image_url: 3 }],
  ])('rejeita item com %s', (_, patch) => {
    const menu = menuFixture()
    Object.assign(menu.categories[0].items[0], patch)

    expect(isMenu(menu)).toBe(false)
  })

  it('rejeita categoria sem items', () => {
    const menu = menuFixture() as unknown as { categories: Array<Record<string, unknown>> }
    delete menu.categories[0].items

    expect(isMenu(menu)).toBe(false)
  })
})
