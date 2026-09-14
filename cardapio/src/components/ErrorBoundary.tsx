import { Component, type ReactNode } from 'react'

type Props = { children: ReactNode }
type State = { crashed: boolean }

/**
 * Última rede de segurança contra tela branca.
 *
 * Sem isto, qualquer erro durante a renderização desmonta a árvore inteira do
 * React e o cliente na mesa vê uma página vazia, sem saber o que fazer. O
 * menu-guard já barra snapshot malformado; isto pega o que ninguém previu.
 *
 * Classe porque o React só oferece error boundary como classe.
 */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { crashed: false }

  static getDerivedStateFromError(): State {
    return { crashed: true }
  }

  componentDidCatch(error: unknown): void {
    /* Aparece no console remoto do celular (chrome://inspect) ao depurar. */
    console.error('Cardápio quebrou ao renderizar:', error)
  }

  render() {
    if (this.state.crashed) {
      return (
        <div className="feedback">
          <p>Não foi possível exibir o cardápio.</p>
          <button type="button" onClick={() => window.location.reload()}>
            Tentar de novo
          </button>
        </div>
      )
    }

    return this.props.children
  }
}
