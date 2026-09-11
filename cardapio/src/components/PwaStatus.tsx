import { useEffect, useState } from 'react'

/**
 * Indicador de diagnóstico da PWA.
 *
 * TEMPORÁRIO: existe para responder, no próprio celular, por que o offline
 * funciona ou não. Sem DevTools no aparelho, esta é a forma de enxergar.
 * Remover quando o offline estiver validado em produção.
 */

type Status = {
  secureContext: boolean
  supported: boolean
  controlled: boolean
}

function readStatus(): Status {
  return {
    /* Falso em http://IP sem a flag: o navegador recusa service worker. */
    secureContext: window.isSecureContext,
    supported: 'serviceWorker' in navigator,
    /* Só é verdadeiro quando o SW assumiu a página: aí o offline funciona. */
    controlled: 'serviceWorker' in navigator && navigator.serviceWorker.controller !== null,
  }
}

export function PwaStatus() {
  const [status, setStatus] = useState<Status>(readStatus)

  useEffect(() => {
    if (!('serviceWorker' in navigator)) return

    const update = () => setStatus(readStatus())

    navigator.serviceWorker.addEventListener('controllerchange', update)
    navigator.serviceWorker.ready.then(update).catch(update)

    return () => {
      navigator.serviceWorker.removeEventListener('controllerchange', update)
    }
  }, [])

  const offlineReady = status.secureContext && status.supported && status.controlled

  return (
    <p className={`pwa-status ${offlineReady ? 'pwa-status--ok' : 'pwa-status--warn'}`}>
      {offlineReady
        ? 'Offline pronto: o cardápio já está salvo neste aparelho.'
        : 'Offline indisponível.'}
      <br />
      origem segura: {status.secureContext ? 'sim' : 'NÃO'} · suporte:{' '}
      {status.supported ? 'sim' : 'NÃO'} · no controle:{' '}
      {status.controlled ? 'sim' : 'NÃO'}
    </p>
  )
}
