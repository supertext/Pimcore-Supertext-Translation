#!/usr/bin/env node
/**
 * Records the install and configuration movie (MP4 only, no GIF) from a demo whose
 * SUPERTEXT_API_URL points at the stand-in API (stand-in.mjs), like movie.mjs. An administrator
 * installs the bundle (terminal: Composer, bundles.php, bundle install, assets, cache, the API key
 * as environment variable and supertext:check), opens Supertext → Settings in Pimcore Studio,
 * checks the language mapping and shows where editors find "Translate with Supertext".
 * See "Demo movie" in docs/DEVELOPER.md.
 *
 *   BASE_URL=http://127.0.0.1:8080 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… npm run movie:install
 *
 * The terminal shows the made-up key st-demo-0000-0000-0000 (give the demo that key: the stand-in
 * accepts any). The settings screen shows the live API address instead of the stand-in's. MOVIE_VOICE=0
 * makes a silent movie. MOVIE_OUT: where the MP4 goes (default
 * tests/docs/movie/out/Pimcore-Supertext-Translation-install.mp4, git-ignored). Needs ffmpeg.
 */
import { chromium } from 'playwright'
import { fileURLToPath } from 'node:url'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { MovieKit } from './movie/movie-kit.mjs'

const base = (process.env.BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '')
const here = (p) => fileURLToPath(new URL(p, import.meta.url))
const out = process.env.MOVIE_OUT ?? here('movie/out/Pimcore-Supertext-Translation-install.mp4')
const need = (name) => process.env[name] ?? (() => { throw new Error(`Set ${name}`) })()
const FAKE_KEY = 'st-demo-0000-0000-0000'
const LIVE_API = 'https://api.supertext.com/v1/'

const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {})
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' })
// The settings screen shows the API address: show Supertext's live one, never the local stand-in.
await page.addInitScript((live) => {
  const fix = () => document.querySelectorAll('.supertext-settings-status code').forEach((c) => { if (c.textContent !== live) c.textContent = live })
  new MutationObserver(fix).observe(document, { childList: true, subtree: true, characterData: true })
}, LIVE_API)

/** Signs in to Pimcore Studio as the demo administrator: configuration is an administrator's task. */
async function signIn () {
  await page.goto(base + '/pimcore-studio/')
  await page.locator('input[type=password]').or(page.getByText('Document Tree')).first().waitFor({ timeout: 120000 })
  if (await page.locator('input[type=password]').isVisible()) { // the second time, the session is still there
    await page.locator('input[name=username]').fill(need('DEMO_ADMIN_EMAIL'))
    await page.locator('input[type=password]').fill(need('DEMO_ADMIN_PASSWORD'))
    await page.keyboard.press('Enter')
  }
  await page.getByText('Document Tree').first().waitFor({ timeout: 60000 })
  await page.waitForTimeout(2000)
  await page.locator('.flexlayout__border_button').first().click() // the Document Tree icon folds the tree: the caption never covers the toolbar
  await page.waitForTimeout(800)
}
/** Waits until a frame of the page (Studio shows documents in an iframe) shows `text`. */
async function frameText (text) {
  for (let i = 0; i < 120; i++) {
    for (const f of page.frames()) if (await f.getByText(text, { exact: true }).count().catch(() => 0) > 0) return
    await page.waitForTimeout(250)
  }
  throw new Error(`"${text}" not shown`)
}
/** Moves the real mouse out of the way (Studio shows tooltips where it rests). */
const park = () => page.mouse.move(1240, 360)
await signIn() // also proves the demo is up before anything is recorded
await page.goto('about:blank')

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pimcore-movie-install-'))
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') })

/** More commands in the terminal kit.terminal() opened (same look; kit.terminal() would start a new one). */
async function typeMore (lines) {
  await page.evaluate(() => document.querySelector('#x > .p.c:last-child')?.remove())
  for (const l of lines) {
    if (l.cmd !== undefined) {
      await page.evaluate(() => {
        const x = document.getElementById('x')
        const p = document.createElement('span'); p.className = 'p'; p.textContent = '$ '; x.appendChild(p)
        const c = document.createElement('span'); c.className = 'c'; x.appendChild(c)
      })
      for (const ch of l.cmd) { await page.evaluate((ch) => { document.querySelector('#x .c').textContent += ch }, ch); await page.waitForTimeout(38) }
      await page.waitForTimeout(450)
      await page.evaluate(() => { document.querySelector('#x .c').classList.remove('c'); document.getElementById('x').append('\n') })
    } else {
      await page.evaluate((o) => { const s = document.createElement('span'); s.className = 'o'; s.textContent = o + '\n'; document.getElementById('x').appendChild(s) }, l.out ?? '')
      await page.waitForTimeout(l.wait ?? 180)
    }
  }
  await page.evaluate(() => { const c = document.createElement('span'); c.className = 'p c'; c.textContent = '$ '; document.getElementById('x').appendChild(c) })
}
/** Starts a new screen of the terminal (the window keeps its place). */
const clearTerminal = () => page.evaluate(() => { document.getElementById('x').textContent = '' })

// 1. Terminal: the commands from docs/INSTALLATION.md (output shortened).
await kit.say("First, add the bundle's GitHub repository to your Pimcore project's Composer settings.", {
  caption: 'Add the repository to <b>Composer</b>',
  during: () => kit.terminal({ title: 'my-site', lines: [
    { cmd: 'composer config repositories.supertext vcs https://github.com/Supertext/Pimcore-Supertext-Translation' },
  ] }),
})
await kit.say('Then require the package. Composer downloads it into your project.', {
  caption: 'Require the bundle with <b>Composer</b>',
  during: () => typeMore([
    { cmd: 'composer require supertext/pimcore-supertext-translation:dev-main' },
    { out: 'Package operations: 1 install, 0 updates, 0 removals' },
    { out: '  - Installing supertext/pimcore-supertext-translation (dev-main)' },
  ]),
})
await kit.say('Register it in config, bundles dot P H P.', {
  caption: 'Register the bundle in <b>config/bundles.php</b>',
  during: () => typeMore([
    { cmd: 'grep Supertext config/bundles.php' },
    { out: "    Supertext\\PimcoreTranslationBundle\\SupertextTranslationBundle::class => ['all' => true]," },
  ]),
})
await kit.say('Then install the bundle with Pimcore\'s console, publish its files for Pimcore Studio, and clear the cache.', {
  caption: '<b>pimcore:bundle:install</b>, <b>assets:install</b>, <b>cache:clear</b>',
  during: async () => {
    await clearTerminal()
    await typeMore([
      { cmd: 'bin/console pimcore:bundle:install SupertextTranslationBundle' },
      { out: ' [OK] Bundle "SupertextTranslationBundle" was successfully installed' },
      { cmd: 'bin/console assets:install public' },
      { out: ' [OK] All assets were successfully installed.' },
      { cmd: 'bin/console cache:clear' },
      { out: ' [OK] Cache for the "prod" environment (debug=false) was successfully cleared.' },
    ])
  },
})
await kit.say('Generate your API key at supertext.com, under Integrations, A P I. No account yet? Create one at supertext.com.', {
  caption: 'API key: <b>supertext.com → Integrations → API</b><br>(requires the Admin role)<br>No account yet? Create one at <b>supertext.com</b>',
  during: async () => {
    await kit.hold(900)
    await clearTerminal()
    await typeMore([{ cmd: `echo 'SUPERTEXT_API_KEY=${FAKE_KEY}' >> .env.local` }])
  },
})
await kit.say('The key is an environment variable. Check it with supertext check: the connection to Supertext works.', {
  caption: 'Check the key: <b>supertext:check</b>',
  during: () => typeMore([
    { cmd: 'bin/console supertext:check' },
    { out: 'API address: https://api.supertext.com/v1/' },
    { out: 'Languages (Pimcore → Supertext):' },
    { out: '  en → en-GB' },
    { out: '  de_CH → de-CH (more)' },
    { out: '  fr_CH → fr-CH (more)' },
    { out: '  it_CH → it-CH (more)' },
    { out: 'API key checked: the connection to Supertext works.' },
  ]),
})
await kit.hold(300)

// 2. Pimcore Studio as administrator: the languages (loading time cut).
kit.pause()
await signIn()
await kit.sync()
kit.resume()
await kit.say("Now sign in to Pimcore Studio as an administrator. Supertext translates into the languages from Pimcore's system settings.", {
  caption: 'Languages: <b>System → System Settings → Localization</b>',
  during: async () => {
    await kit.click(page.getByTestId('main-nav-trigger')) // main menu
    const system = page.getByText('System', { exact: true }).first()
    await kit.click(system)
    await kit.click(page.getByText('System Settings', { exact: true }).first())
    await park()
    const l10n = page.getByText('Localization & Internationalization (l10n/i18n)').first()
    await kit.click(l10n)
    await park()
    await page.getByText('Swiss High German (de_CH)').first().waitFor({ timeout: 30000 })
    await kit.sync()
  },
})

// 3. Supertext → Settings.
await kit.say('In the main menu, open Supertext, Settings.', {
  caption: 'Open <b>Supertext → Settings</b>',
  during: async () => {
    await kit.click(page.getByTestId('main-nav-trigger'))
    await kit.click(page.getByText('Supertext', { exact: true }).first())
    await kit.click(page.getByText('Settings', { exact: true }).last())
    await park()
    await page.locator('.supertext-settings-languages').waitFor({ timeout: 30000 })
    await kit.sync()
  },
})
const keyRow = page.locator('.supertext-settings-status tr').first()
await kit.say('The API key is set, from the environment variable.', {
  caption: 'API key: <b>Set</b> (environment variable)',
  during: async () => { await kit.hold(300); await kit.moveTo(keyRow.locator('td div').first()) },
})

// 4. The language mapping.
const table = page.locator('.supertext-settings-languages')
const germanRow = table.locator('tbody tr').nth(1)
const germanSelect = germanRow.locator('.ant-select').first()
await kit.say('Each Pimcore language has a Supertext language, already preselected.', {
  caption: '<b>Languages</b>: a Supertext language for each Pimcore language',
  during: async () => {
    await germanRow.evaluate((el) => el.scrollIntoView({ block: 'center', behavior: 'smooth' }))
    await kit.hold(900)
    await kit.moveTo(germanSelect)
  },
})
await kit.say('Open one to pick another variant. We keep German, Switzerland.', {
  caption: 'Choose <b>German (Switzerland) (de-CH)</b>',
  during: async () => {
    await kit.click(germanSelect)
    await page.keyboard.type('Switzerland', { delay: 90 })
    await kit.hold(600)
    const option = page.locator('.ant-select-dropdown:visible .ant-select-item-option', { hasText: '(de-CH)' }).first()
    await kit.click(option)
    await park()
  },
})
const review = germanRow.locator('.ant-checkbox-wrapper, .ant-checkbox').first()
await kit.say('Translation review can be switched on per language. We leave it off.', {
  caption: '<b>Translation review</b>: optional, per language (left off)',
  during: async () => { await kit.hold(300); await kit.moveTo(review) },
})
const save = page.locator('.supertext-settings button', { hasText: 'Save' }).last()
await kit.say('Save.', {
  caption: 'Click <b>Save</b>',
  during: async () => {
    await kit.click(save)
    await park()
    await page.getByText('The settings have been saved.').first().waitFor({ timeout: 30000 })
    await page.getByText('The settings have been saved.').first().evaluate((el) => el.scrollIntoView({ block: 'center', behavior: 'smooth' }))
    await kit.hold(500)
    await kit.sync()
  },
})

// 5. Where editors find it.
await kit.say("That's it. Editors now find Translate with Supertext at the bottom of every page and data object.", {
  caption: 'Done: editors find <b>Translate with Supertext</b>',
  during: async () => {
    await kit.click(page.locator('.flexlayout__border_button').first()) // unfold the document tree
    await kit.click(page.getByText('en', { exact: true }).first(), { clickCount: 2 })
    await park()
    await frameText('Welcome')
    await kit.click(page.locator('.flexlayout__border_button').first()) // fold it again
    await park()
    await kit.moveTo(page.locator('.supertext-translate-button').last())
  },
  after: 300,
})
await kit.caption('')

fs.mkdirSync(path.dirname(out), { recursive: true })
await kit.finish(out, { system: 'Pimcore', repo: 'Pimcore-Supertext-Translation', kind: 'install' })
await browser.close()
fs.rmSync(workDir, { recursive: true, force: true })
console.log(`Saved ${out}`)
