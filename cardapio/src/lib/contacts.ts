import type { Contacts } from '../types/menu'

/**
 * Contatos do rodapé: do snapshot para links. Funções puras.
 *
 * Os valores chegam já normalizados (painel + CHECK no banco) e conferidos
 * pelo menu-guard; mesmo assim, tudo que entra na URL passa por
 * encodeURIComponent. Defesa barata.
 */

export type ContactLink = {
  kind: 'whatsapp' | 'instagram' | 'address'
  label: string
  href: string
}

const NO_CONTACTS: Contacts = { whatsapp: null, instagram: null, address: null }

/** Snapshots anteriores aos contatos não têm o objeto: sem links. */
export function normalizeContacts(contacts: Contacts | undefined): Contacts {
  return contacts ?? NO_CONTACTS
}

/** Só os contatos preenchidos, na ordem em que aparecem no rodapé. */
export function contactLinks({ whatsapp, instagram, address }: Contacts): ContactLink[] {
  const links: ContactLink[] = []

  if (whatsapp) {
    links.push({ kind: 'whatsapp', label: 'WhatsApp', href: `https://wa.me/${encodeURIComponent(whatsapp)}` })
  }
  if (instagram) {
    links.push({ kind: 'instagram', label: `@${instagram}`, href: `https://instagram.com/${encodeURIComponent(instagram)}` })
  }
  if (address) {
    links.push({
      kind: 'address',
      label: address,
      href: `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}`,
    })
  }

  return links
}
