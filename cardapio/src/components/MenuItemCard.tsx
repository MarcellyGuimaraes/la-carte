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
