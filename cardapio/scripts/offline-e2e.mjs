// Teste E2E de offline e resiliência da PWA, num navegador de verdade.
//
// Por que existe: o service worker, o Cache Storage e o "sem rede" só existem
// num navegador. Os testes do Vitest cobrem as decisões (menu-loader); este
// confere que o conjunto funciona de ponta a ponta.
//
// Pré-requisitos:
//   - docker compose up -d   (MinIO la-carte-minio no ar)
//   - cardapio/.env.local com VITE_SNAPSHOT_BASE_URL=http://127.0.0.1:9000/la-carte
//   - Edge ou Chrome instalado (EDGE_PATH/CHROME_PATH para outro caminho)
//   - porta 4173 livre (feche o npm run preview)
//
// Rodar:  npm run test:e2e
//
// Não usa o painel nem o banco: publica snapshots de um restaurante fictício
// (e2e-resiliencia) direto no bucket e apaga tudo no fim. Derruba e religa o
// container do MinIO para simular "sem rede".

import { execSync, spawn } from 'node:child_process'
import { existsSync, mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const SLUG = 'e2e-resiliencia'
const APP = `http://localhost:4173/${SLUG}?debug`
const MINIO = 'la-carte-minio'
const CDP_PORT = 9335
const CWD = new URL('..', import.meta.url)

const BROWSER = [
  process.env.EDGE_PATH,
  process.env.CHROME_PATH,
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  '/usr/bin/google-chrome',
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
].find((path) => path && existsSync(path))

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))
const results = []

/* ---------- storage (mc dentro do container do MinIO) ---------- */

function mc(script, input) {
  execSync(`docker exec -i ${MINIO} sh -c "mc alias set e2e http://127.0.0.1:9000 la_carte la_carte_dev >/dev/null && ${script}"`, {
    input,
    stdio: ['pipe', 'ignore', 'pipe'],
  })
}

function upload(file, body, cacheControl) {
  mc(
    `mc pipe --attr 'Content-Type=application/json;Cache-Control=${cacheControl}' e2e/la-carte/menus/${SLUG}/${file}`,
    typeof body === 'string' ? body : JSON.stringify(body),
  )
}

const publishVersion = (n, body) => upload(`v${n}.json`, body, 'public, max-age=31536000, immutable')
const movePointer = (n) => upload('current.json', { version: n }, 'no-store')
const cleanBucket = () => {
  try {
    mc(`mc rm --recursive --force e2e/la-carte/menus/${SLUG}/`)
  } catch {
    /* nada para apagar */
  }
}

function menu(label) {
  return {
    tenant: { id: 999, name: `E2E ${label}`, slug: SLUG },
    generated_at: '2026-09-13T18:00:00Z',
    categories: [
      {
        id: 1,
        name: 'Bebidas',
        sort_order: 1,
        items: [
          { id: 1, name: 'Chopp', description: null, price_cents: 1200, image_url: null, featured: false, available: true, sort_order: 1 },
        ],
      },
    ],
  }
}

/* ---------- processos ---------- */

async function waitHttp(url, up = true) {
  for (let i = 0; i < 80; i++) {
    const ok = await fetch(url).then(() => true, () => false)
    if (ok === up) return
    await sleep(250)
  }
  throw new Error(`timeout esperando ${url} ${up ? 'subir' : 'cair'}`)
}

function killTree(child) {
  if (!child) return
  try {
    if (process.platform === 'win32') execSync(`taskkill /pid ${child.pid} /T /F`, { stdio: 'ignore' })
    else process.kill(-child.pid, 'SIGKILL')
  } catch {
    /* já morreu */
  }
}

const startPreview = () =>
  spawn('npx vite preview --port 4173 --strictPort', { cwd: CWD, shell: true, stdio: 'ignore', detached: process.platform !== 'win32' })

async function goOffline(preview) {
  killTree(preview)
  execSync(`docker stop ${MINIO}`, { stdio: 'ignore' })
  await waitHttp('http://localhost:4173/', false)
  await waitHttp('http://127.0.0.1:9000/', false)
}

async function goOnline() {
  execSync(`docker start ${MINIO}`, { stdio: 'ignore' })
  await waitHttp('http://127.0.0.1:9000/minio/health/live')
  const preview = startPreview()
  await waitHttp('http://localhost:4173/')
  return preview
}

/* ---------- navegador via DevTools Protocol ---------- */

async function openPage(browserContextId) {
  const version = await (await fetch(`http://127.0.0.1:${CDP_PORT}/json/version`)).json()
  const browser = await connect(version.webSocketDebuggerUrl)
  const { result } = await browser.send('Target.createTarget', { url: 'about:blank', ...(browserContextId && { browserContextId }) })
  browser.close()

  const page = await connect(`ws://127.0.0.1:${CDP_PORT}/devtools/page/${result.targetId}`)
  await page.send('Page.enable')
  await page.send('Runtime.enable')
  return page
}

async function newIsolatedContext() {
  const version = await (await fetch(`http://127.0.0.1:${CDP_PORT}/json/version`)).json()
  const browser = await connect(version.webSocketDebuggerUrl)
  const { result } = await browser.send('Target.createBrowserContext')
  browser.close()
  return result.browserContextId
}

async function connect(url) {
  const ws = new WebSocket(url)
  await new Promise((resolve, reject) => {
    ws.addEventListener('open', resolve)
    ws.addEventListener('error', reject)
  })
  let id = 0
  const pending = new Map()
  ws.addEventListener('message', (event) => {
    const message = JSON.parse(event.data)
    if (message.id && pending.has(message.id)) {
      pending.get(message.id)(message)
      pending.delete(message.id)
    }
  })

  const send = (method, params = {}) =>
    new Promise((resolve) => {
      const n = ++id
      pending.set(n, resolve)
      ws.send(JSON.stringify({ id: n, method, params }))
    })

  const evaluate = async (expression) =>
    (await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })).result?.result?.value

  return { send, evaluate, close: () => ws.close() }
}

const readScreen = (page) =>
  page.evaluate(`({
    title: document.querySelector('.header__title')?.textContent ?? null,
    feedback: document.querySelector('.feedback')?.textContent ?? null,
    text: document.body.innerText.trim(),
  })`)

async function expectScreen(page, label, check) {
  let screen
  for (let i = 0; i < 40; i++) {
    screen = await readScreen(page)
    if (screen && check(screen)) {
      results.push({ label, ok: true, screen })
      console.log(`  ✔ ${label} → ${screen.title ?? screen.feedback}`)
      return
    }
    await sleep(250)
  }
  results.push({ label, ok: false, screen })
  console.log(`  ✘ ${label} → tela: ${JSON.stringify(screen)}`)
}

const showsMenu = (label) => (screen) => screen.title === `E2E ${label}`
const notBlank = (screen) => screen.text.length > 0

/* ---------- roteiro ---------- */

async function main() {
  if (!BROWSER) throw new Error('Edge/Chrome não encontrado. Defina EDGE_PATH ou CHROME_PATH.')

  console.log('» build da PWA')
  execSync('npm run build', { cwd: CWD, stdio: 'ignore' })

  console.log('» publicando v1 de teste no MinIO')
  cleanBucket()
  publishVersion(1, menu('v1'))
  movePointer(1)

  const profile = mkdtempSync(join(tmpdir(), 'cardapio-e2e-'))
  const browser = spawn(BROWSER, ['--headless=new', `--remote-debugging-port=${CDP_PORT}`, `--user-data-dir=${profile}`, '--no-first-run', 'about:blank'], { stdio: 'ignore' })
  let preview = startPreview()

  try {
    await waitHttp('http://localhost:4173/')
    await waitHttp(`http://127.0.0.1:${CDP_PORT}/json/version`)
    const page = await openPage()

    console.log('\n1) Primeira visita, online')
    await page.send('Page.navigate', { url: APP })
    await expectScreen(page, 'mostra a v1 publicada', showsMenu('v1'))
    await page.evaluate(`navigator.serviceWorker.ready.then(() => new Promise(r => navigator.serviceWorker.controller ? r() : navigator.serviceWorker.addEventListener('controllerchange', r)))`)
    await sleep(1500)

    console.log('\n2) Sem rede: current.json inacessível')
    await goOffline(preview)
    await page.send('Page.reload')
    await expectScreen(page, 'abre a última versão do cache', showsMenu('v1'))

    console.log('\n3) Ponteiro aponta para versão que não baixa')
    preview = await goOnline()
    movePointer(2) /* v2 nunca foi gravado: 404 */
    await page.send('Page.reload')
    await sleep(1500)
    await expectScreen(page, 'continua na v1, sem erro', (s) => showsMenu('v1')(s) && s.feedback === null)

    console.log('\n4) Snapshot novo com JSON válido mas fora do contrato')
    publishVersion(3, { tenant: { id: 999 } })
    movePointer(3)
    await page.send('Page.reload')
    await sleep(1500)
    await expectScreen(page, 'continua na v1, sem tela branca', (s) => showsMenu('v1')(s) && s.feedback === null)

    console.log('\n5) Sem rede e a versão lembrada sumiu do aparelho')
    await page.evaluate(`localStorage.setItem('menu-version:${SLUG}', '999')`)
    await goOffline(preview)
    await page.send('Page.reload')
    await expectScreen(page, 'cai para a versão que está no cache', showsMenu('v1'))

    console.log('\n6) Aparelho novo (sem cache) e snapshot publicado quebrado')
    preview = await goOnline()
    const fresh = await openPage(await newIsolatedContext())
    await fresh.send('Page.navigate', { url: APP })
    await expectScreen(fresh, 'mostra mensagem, não tela branca', (s) => notBlank(s) && s.feedback !== null && s.title === null)
    fresh.close()

    console.log('\n7) Publicação corrigida e a conexão volta')
    publishVersion(4, menu('v4'))
    movePointer(4)
    await page.evaluate(`window.dispatchEvent(new Event('online'))`)
    await expectScreen(page, 'troca para a v4 sem recarregar', showsMenu('v4'))

    console.log('\n8) E a v4 também abre offline')
    await goOffline(preview)
    await page.send('Page.reload')
    await expectScreen(page, 'abre a v4 do cache', showsMenu('v4'))
    preview = null
    page.close()
  } finally {
    killTree(preview)
    killTree(browser)
    execSync(`docker start ${MINIO}`, { stdio: 'ignore' })
    await waitHttp('http://127.0.0.1:9000/minio/health/live').catch(() => {})
    cleanBucket()
    rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 })
  }

  const failed = results.filter((r) => !r.ok)
  console.log(`\n${results.length - failed.length}/${results.length} cenários ok`)
  process.exitCode = failed.length === 0 ? 0 : 1
}

main().catch((error) => {
  console.error(error)
  process.exitCode = 1
})
