#!/usr/bin/env node
/**
 * End-to-end test against the REAL Supertext API: a fresh demo (nothing translated yet) with a
 * real SUPERTEXT_API_KEY. The editor (DEMO_EDITOR_*) translates the demo's English pages and the
 * sample article into every other language in Pimcore Studio, then the content is checked
 * (export.php, run inside the demo container: the latest versions, as Studio shows them):
 * every page has a linked translation in each language, new pages are unpublished, the article's
 * published data is unchanged, fields are filled and really in the target language (de-CH
 * without "ß"), no protocol leftovers, formatting and links kept, and translating again updates
 * the same documents and object instead of adding new ones.
 * See "End-to-end tests" in docs/DEVELOPER.md.
 *
 *   STUDIO_URL=http://127.0.0.1:8081 DEMO_CONTAINER=demo-live \
 *   EDITOR_EMAIL=… EDITOR_PASSWORD=… node tests/e2e/live.mjs
 *
 * The home page (/en) and the article are translated through the Supertext dialog in Studio;
 * the page below it (/en/swiss-chocolate) through the same Studio API route the dialog calls
 * (POST /pimcore-studio/api/supertext/elements/document/{id}/translate), in the editor's session,
 * because opening a nested page needs fixed-position clicks in Studio's tree.
 */
import { chromium } from 'playwright'
import { execFileSync } from 'node:child_process'
import { mkdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const B = (process.env.STUDIO_URL ?? 'http://127.0.0.1:8081').replace(/\/$/, '')
const CONTAINER = process.env.DEMO_CONTAINER ?? 'demo-live'
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`) })()
const artifacts = fileURLToPath(new URL('./artifacts/', import.meta.url))
mkdirSync(artifacts, { recursive: true })

const failures = []
function check (name, ok, detail = '') {
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${detail ? ` — ${detail}` : ''}`)
  if (!ok) failures.push(`${name}${detail ? ` — ${detail}` : ''}`)
  return ok
}

// Common words, to tell the target languages apart. Lower case, whole words.
const STOPWORDS = {
  en: ['the', 'and', 'is', 'are', 'with', 'for', 'you', 'your', 'our', 'of', 'to', 'we', 'who', 'what', 'in', 'from', 'by'],
  de: ['der', 'die', 'das', 'und', 'ist', 'sind', 'mit', 'für', 'sie', 'ihre', 'ihr', 'unser', 'unsere', 'unserer', 'wir', 'eine', 'ein', 'den', 'zu', 'von', 'was', 'im', 'aus', 'auf', 'innerhalb'],
  fr: ['le', 'la', 'les', 'et', 'est', 'sont', 'avec', 'pour', 'vous', 'votre', 'vos', 'notre', 'nos', 'nous', 'des', 'une', 'un', 'du', 'de', 'qui', 'que', 'dans', 'en'],
  it: ['il', 'lo', 'la', 'gli', 'e', 'è', 'sono', 'con', 'per', 'lei', 'sua', 'suo', 'nostro', 'nostra', 'noi', 'una', 'un', 'del', 'della', 'di', 'che', 'chi', 'nel', 'nella', 'da', 'entro'],
}
const words = (text) => text.toLowerCase().match(/[\p{L}]+/gu) ?? []
const score = (text, lang) => words(text).filter((w) => STOPWORDS[lang].includes(w)).length
const plain = (v) => String(v ?? '').replace(/<[^>]+>/g, ' ')
const count = (haystack, needle) => String(haystack ?? '').split(needle).length - 1
const LEFTOVERS = ['data-st-id', 'data-st-ph', 'st-ph-']
const TAGS = [['links', '<a '], ['bold', '<strong'], ['italics', '<em'], ['list items', '<li'], ['paragraphs', '<p']]

/** The demo's content at its latest versions (tests/e2e/export.php inside the container). */
function snapshot () {
  execFileSync('docker', ['cp', fileURLToPath(new URL('./export.php', import.meta.url)), `${CONTAINER}:/tmp/e2e-export.php`])
  // The entrypoint derives DATABASE_URL (MYSQL_URL + PIMCORE_DB_NAME) for its own processes only; docker exec doesn't see it.
  const env = process.env.DEMO_DATABASE_URL ? ['-e', `DATABASE_URL=${process.env.DEMO_DATABASE_URL}`] : []
  const out = execFileSync('docker', ['exec', ...env, '-u', 'www-data', '-w', '/app/demo/project', CONTAINER, 'php', '/tmp/e2e-export.php'], { encoding: 'utf8', maxBuffer: 32 << 20 })
  const json = out.split('@@E2E@@\n')[1]
  if (!json) throw new Error(`export.php printed no result:\n${out}`)
  return JSON.parse(json)
}

/** Translatable texts of a page (DocumentTranslator::units() with the default editable types). */
function units (page) {
  const out = {}
  for (const f of ['title', 'description', 'navigation_name', 'navigation_title']) if (page[f]) out[f] = page[f]
  for (const [name, e] of Object.entries(page.editables ?? {})) {
    if (['input', 'textarea', 'wysiwyg'].includes(e.type) && typeof e.data === 'string' && plain(e.data).trim()) out[name] = e.data
    if (e.type === 'link' && e.data?.text) out[`${name}.text`] = e.data.text
  }
  return out
}

// --- Setup -------------------------------------------------------------------------------
const before = snapshot()
const languages = before.languages
const source = 'en'
const targets = languages.filter((l) => l !== source)
console.log(`Demo: ${before.pages.length} English pages, article ${before.object ? 'present' : 'missing'}; targets: ${targets.join(', ')}`)
check('demo has target languages', targets.length > 0)
check('demo has English pages and the sample article', before.pages.length > 0 && before.object !== null)
check('fresh demo (nothing translated yet)', before.pages.every((p) => Object.keys(p.translations).length === 0) && before.counts.notes === 0)

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const page = await browser.newPage({ viewport: { width: 1400, height: 900 }, deviceScaleFactor: 1, locale: 'en-US' })
page.setDefaultTimeout(30_000)
const shot = async (name) => page.screenshot({ path: `${artifacts}${name}.png` }).catch(() => {})

// --- Log in as the editor ----------------------------------------------------------------
await page.goto(`${B}/pimcore-studio/`)
await page.waitForSelector('input[type=password]', { timeout: 180_000 })
await page.locator('input[name=username]').fill(need('EDITOR_EMAIL'))
await page.locator('input[type=password]').fill(need('EDITOR_PASSWORD'))
await page.keyboard.press('Enter')
const loggedIn = await page.getByText('Document Tree').first().waitFor({ timeout: 90_000 }).then(() => true, () => false)
if (!check('editor can log in to Pimcore Studio (DEMO_EDITOR_*)', loggedIn)) {
  await shot('login')
  throw new Error(failures.join('\n'))
}
await page.waitForTimeout(2000)

// Studio keeps every open editor tab in the page; click the Supertext button that is on top.
async function clickTranslateButton () {
  for (let attempt = 0; attempt < 60; attempt++) {
    for (const button of await page.locator('.supertext-translate-button').all()) {
      const box = await button.boundingBox()
      if (!box || box.width === 0) continue
      const onTop = await page.evaluate(([x, y]) => document.elementFromPoint(x, y)?.closest('.supertext-translate-button') !== null, [box.x + box.width / 2, box.y + box.height / 2])
      if (onTop) return button.click()
    }
    await page.waitForTimeout(1000)
  }
  throw new Error('No Supertext button on screen')
}

/** In the open editor: the Supertext dialog, every target language, overwrite if needed, translate. */
async function translateInDialog (label) {
  await clickTranslateButton()
  const modal = page.locator('.ant-modal-content').last()
  await modal.locator('.supertext-targets, .supertext-results, .supertext-error').first().waitFor()
  await page.waitForTimeout(800)
  const loadError = await modal.locator('.supertext-error').allTextContents()
  check(`${label}: dialog opens without errors`, loadError.length === 0, loadError.join(' | '))
  const boxes = modal.locator('.supertext-targets input[type=checkbox]')
  let enabled = 0
  for (const box of await boxes.all()) {
    if (await box.isDisabled()) continue
    enabled++
    if (!(await box.isChecked())) await box.check()
  }
  check(`${label}: every target language can be selected`, enabled === targets.length, `${enabled}/${targets.length}`)
  const overwrite = modal.locator('.supertext-overwrite-checkbox input[type=checkbox]')
  if (await overwrite.count()) await overwrite.check()
  await shot(`${label}-dialog`)
  const started = Date.now()
  await modal.locator('.supertext-submit').click()
  await modal.locator('.supertext-results, .supertext-error').first().waitFor({ timeout: 900_000 })
  await page.waitForTimeout(800)
  console.log(`${label}: ${Math.round((Date.now() - started) / 1000)} s`)
  await shot(`${label}-result`)
  const ok = await modal.locator('.supertext-results .ant-alert-success').count()
  const errors = [
    ...(await modal.locator('.supertext-error').allTextContents()),
    ...(await modal.locator('.supertext-results .ant-alert-error, .supertext-results .ant-alert-info').allTextContents()),
  ]
  check(`${label}: every language translated`, ok === targets.length, `${ok}/${targets.length}`)
  check(`${label}: no error shown`, errors.length === 0, errors.map((e) => e.replace(/\s+/g, ' ').trim()).join(' | '))
  await page.getByRole('button', { name: /^(Close|Cancel)$/ }).last().click()
  await page.waitForTimeout(3000)
}

async function openHomePage () {
  await page.getByText('en', { exact: true }).first().dblclick()
  await page.waitForTimeout(3000)
}

async function openArticle () {
  const article = page.getByText('swiss-chocolate', { exact: true }).filter({ visible: true })
  if (!(await article.count())) {
    if (!(await page.getByText('Articles', { exact: true }).filter({ visible: true }).count())) await page.mouse.click(28, 873) // Data Object Tree
    await page.getByText('Articles', { exact: true }).filter({ visible: true }).first().waitFor()
    if (!(await article.count())) await page.mouse.click(97, 89) // expand "Articles"
  }
  await article.first().dblclick()
  await page.waitForTimeout(3000)
}

/** The dialog's own API call, in the editor's Studio session (for pages Studio's tree hides). */
async function translateViaRoute (label, id, overwrite) {
  const started = Date.now()
  const res = await page.evaluate(async ({ id, targets, overwrite }) => {
    const r = await fetch(`/pimcore-studio/api/supertext/elements/document/${id}/translate`, {
      method: 'POST',
      credentials: 'include',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ targets, overwrite }),
    })
    return { status: r.status, body: await r.json().catch(() => null) }
  }, { id, targets, overwrite })
  console.log(`${label}: ${Math.round((Date.now() - started) / 1000)} s`)
  const results = res.body?.results ?? []
  const bad = results.filter((r) => r.status !== 'translated')
  check(`${label}: every language translated`, res.status === 200 && results.length === targets.length && bad.length === 0,
    `HTTP ${res.status}; ${bad.map((r) => `${r.language}: ${r.status} ${r.message ?? ''}`).join(' | ') || JSON.stringify(res.body).slice(0, 300)}`)
}

/** Runs a step; an exception (e.g. a selector that timed out) is a failed check, not the end of the test. */
async function step (label, fn) {
  try {
    await fn()
  } catch (e) {
    await shot(`${label.replace(/\W+/g, '-')}-error`)
    check(label, false, e.message.split('\n')[0])
  }
}

function checkTexts (tag, lang, pairs) {
  let fields = 0
  let changed = 0
  let text = ''
  for (const [name, s, v] of pairs) {
    fields++
    if (!check(`${tag}: ${name} filled`, plain(v).trim() !== '')) continue
    // A single word can be the same in the target language ("Bern"), so unchanged texts only
    // count against the ratio below.
    if (v !== s) changed++
    else console.log(`note ${tag}: ${name} unchanged ("${plain(s).trim()}")`)
    text += ` ${plain(v)}`
    const left = LEFTOVERS.filter((l) => String(v).includes(l))
    check(`${tag}: ${name} has no protocol leftovers`, left.length === 0, left.join(', '))
    for (const [what, needle] of TAGS) {
      if (count(s, needle)) check(`${tag}: ${name} keeps its ${what}`, count(v, needle) === count(s, needle), `${count(v, needle)}/${count(s, needle)}`)
    }
    for (const href of String(s).match(/href="[^"]*"/g) ?? []) check(`${tag}: ${name} keeps ${href}`, String(v).includes(href))
  }
  check(`${tag}: texts were changed by the translation`, fields > 0 && changed / fields >= 0.8, `${changed}/${fields} fields`)
  const base = lang.split(/[-_]/)[0].toLowerCase()
  if (STOPWORDS[base]) {
    const scores = Object.fromEntries(Object.keys(STOPWORDS).map((l) => [l, score(text, l)]))
    const best = Object.entries(scores).sort((a, b) => b[1] - a[1])[0][0]
    check(`${tag}: text is in the target language`, best === base, JSON.stringify(scores))
  }
  if (/^de[-_]ch$/i.test(lang)) check(`${tag}: Swiss German spelling (no "ß")`, !text.includes('ß'))
}

function checkPages (run, snap) {
  for (const { source: s, translations } of snap.pages) {
    for (const lang of targets) {
      const t = translations[lang]
      const tag = `${run} ${s.path} → ${lang}`
      if (!check(`${tag}: linked translation exists`, t !== undefined)) continue
      check(`${tag}: translation is unpublished`, t.published === false, t.path)
      check(`${tag}: language property ${lang}`, t.language === lang, t.language)
      const su = units(s)
      const tu = units(t)
      checkTexts(tag, lang, Object.keys(su).map((k) => [k, su[k], tu[k]]))
      for (const [name, e] of Object.entries(s.editables)) {
        if (e.type === 'link' && e.data?.path) check(`${tag}: link ${name} keeps its target`, t.editables[name]?.data?.path === e.data.path, t.editables[name]?.data?.path)
      }
    }
  }
}

function checkObject (run, snap) {
  const o = snap.object
  for (const lang of targets) {
    const tag = `${run} article → ${lang}`
    checkTexts(tag, lang, o.fields.filter((f) => plain(o.latest.en[f]).trim()).map((f) => [f, o.latest.en[f], o.latest[lang][f]]))
    check(`${tag}: published data unchanged (new version only)`, o.fields.every((f) => !o.stored[lang][f]))
  }
  check(`${run} article: still published`, o.published === true)
}

// --- Pages: translate, check, translate again (the home page's editor stays open) ----------
const pagesBelow = before.pages.slice(1)
await step('open /en in Studio', openHomePage)
await step('first-run /en', () => translateInDialog('first-run /en'))
for (const p of pagesBelow) await step(`first-run ${p.source.path}`, () => translateViaRoute(`first-run ${p.source.path}`, p.source.id, false))
const pagesFirst = snapshot()
checkPages('first-run', pagesFirst)

await step('second-run /en', () => translateInDialog('second-run /en'))
for (const p of pagesBelow) await step(`second-run ${p.source.path}`, () => translateViaRoute(`second-run ${p.source.path}`, p.source.id, true))
const pagesSecond = snapshot()
checkPages('second-run', pagesSecond)
for (const [i, p] of pagesFirst.pages.entries()) {
  for (const lang of targets) {
    const a = p.translations[lang]
    const b = pagesSecond.pages[i]?.translations[lang]
    if (!a || !b) continue
    const tag = `${p.source.path} → ${lang}`
    check(`${tag}: translating again updates the same document (no duplicate)`, a.id === b.id, `${a.id} → ${b.id}`)
    check(`${tag}: translating again adds a version`, b.versions > a.versions, `${a.versions} → ${b.versions}`)
  }
}
check('translating again adds no documents', pagesSecond.counts.documents === pagesFirst.counts.documents, `${pagesFirst.counts.documents} → ${pagesSecond.counts.documents}`)

// --- The article (data object): translate, check, translate again ---------------------------
await step('open the article in Studio', openArticle)
await step('first-run article', () => translateInDialog('first-run article'))
const objectFirst = snapshot()
checkObject('first-run', objectFirst)
await step('second-run article', () => translateInDialog('second-run article'))
const objectSecond = snapshot()
checkObject('second-run', objectSecond)
check('translating again adds no article objects', objectSecond.counts.articles === before.counts.articles, `${before.counts.articles} → ${objectSecond.counts.articles}`)
check('article: translating again adds a version', objectSecond.object.versions > objectFirst.object.versions, `${objectFirst.object.versions} → ${objectSecond.object.versions}`)
check('one Supertext note per translation', objectSecond.counts.notes === 2 * targets.length * (before.pages.length + 1), `${objectSecond.counts.notes}/${2 * targets.length * (before.pages.length + 1)}`)

await browser.close()
if (failures.length) {
  console.error(`\n${failures.length} check(s) failed:\n- ${failures.join('\n- ')}`)
  process.exit(1)
}
console.log('\nAll end-to-end checks passed.')
