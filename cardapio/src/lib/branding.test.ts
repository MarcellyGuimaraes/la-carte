import { describe, expect, it } from 'vitest'
import { brandingVariables, textOn } from './branding'

describe('textOn', () => {
  it.each([
    ['amarelo', '#ffd400'],
    ['âmbar padrão', '#e8a13a'],
    ['branco', '#ffffff'],
  ])('usa texto escuro sobre %s', (_, color) => {
    expect(textOn(color)).toBe('#14110f')
  })

  it.each([
    ['azul-marinho', '#1a2b5c'],
    ['vinho', '#7a1f2b'],
    ['preto', '#000000'],
  ])('usa texto branco sobre %s', (_, color) => {
    expect(textOn(color)).toBe('#ffffff')
  })
})

describe('brandingVariables', () => {
  it('sem cor de marca não sobrescreve nada: vale o padrão do tema', () => {
    expect(brandingVariables({ theme: 'light', brand_color: null, logo_url: null })).toEqual({})
  })

  it('com cor de marca troca o destaque e o texto sobre ele', () => {
    expect(brandingVariables({ theme: 'dark', brand_color: '#1a2b5c', logo_url: null })).toEqual({
      '--accent': '#1a2b5c',
      '--on-accent': '#ffffff',
    })
  })
})
