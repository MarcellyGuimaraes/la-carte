/**
 * O restaurante vem da URL do QR code: https://<site>/bar-do-tonho
 *
 * Função pura para ficar fácil de raciocinar: recebe o caminho, devolve o slug
 * ou null. Mesmo formato de slug que o painel aceita (minúsculas, números, -).
 */
export function slugFromPath(pathname: string, base: string): string | null {
  const relative = pathname.startsWith(base) ? pathname.slice(base.length) : pathname
  const [first] = relative.split('/').filter(Boolean)

  return first !== undefined && /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(first) ? first : null
}
