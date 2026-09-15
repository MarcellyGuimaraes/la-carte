import type { MenuItem } from '../types/menu'
import { formatPrice } from '../lib/money'

type Props = {
  item: MenuItem
}

/**
 * Um item do cardápio. Recebe tudo por props e não sabe de onde os dados vêm.
 */
export function MenuItemCard({ item }: Props) {
  return (
    <li className={`item ${item.available ? '' : 'item--unavailable'}`}>
      {item.image_url ? (
        <img
          className="item__image"
          src={item.image_url}
          alt={item.name}
          loading="lazy"
          decoding="async"
          width={72}
          height={72}
        />
      ) : (
        /* Sem foto cadastrada: placeholder para o layout ficar igual entre
           todos os itens (e "todos exibem imagem" valer mesmo sem URL). */
        <div className="item__image item__image--placeholder" aria-hidden="true">
          <svg viewBox="0 0 24 24" width={28} height={28} fill="none"
               stroke="currentColor" strokeWidth={1.5} strokeLinecap="round">
            <path d="M6 3v7a2 2 0 0 0 2 2v9M8 3v6M4 3v6M18 3c-1.5 0-2.5 2-2.5 5s1 4 2.5 4v9" />
          </svg>
        </div>
      )}
      <div className="item__text">
        <h3 className="item__name">
          {item.name}
          {item.featured && <span className="item__badge">destaque</span>}
        </h3>
        {item.description && <p className="item__description">{item.description}</p>}
        {!item.available && <p className="item__note">Indisponível hoje</p>}
      </div>
      <span className="item__price">{formatPrice(item.price_cents)}</span>
    </li>
  )
}
