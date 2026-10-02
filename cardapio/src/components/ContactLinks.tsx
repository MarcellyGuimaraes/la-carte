import type { Contacts } from '../types/menu'
import { contactLinks } from '../lib/contacts'

type Props = {
  contacts: Contacts
}

/**
 * Contatos do restaurante no rodapé. Sem nenhum contato, não desenha nada.
 *
 * Abrem fora do cardápio (aba nova / app do WhatsApp, Instagram ou Maps);
 * noopener impede a página aberta de mexer na janela do cardápio. Offline,
 * o link simplesmente não abre: o cardápio continua intacto.
 */
export function ContactLinks({ contacts }: Props) {
  const links = contactLinks(contacts)

  if (links.length === 0) return null

  return (
    <ul className="contacts" aria-label="Contatos do restaurante">
      {links.map((link) => (
        <li key={link.kind}>
          <a className={`contacts__link contacts__link--${link.kind}`} href={link.href} target="_blank" rel="noopener noreferrer">
            {link.label}
          </a>
        </li>
      ))}
    </ul>
  )
}
