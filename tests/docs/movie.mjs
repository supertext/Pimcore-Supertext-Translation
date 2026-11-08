#!/usr/bin/env node
/**
 * Records the demo movie (docs/images/demo.gif and an MP4) from a FRESH demo whose
 * SUPERTEXT_API_URL points at the stand-in API (stand-in.mjs), like screenshots.mjs.
 * The editor opens the English home page in Pimcore Studio, translates it with Supertext
 * into German and French and opens the German page. See "Demo movie" in docs/DEVELOPER.md.
 *
 *   BASE_URL=http://127.0.0.1:8080 DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… npm run movie
 *
 * Voice: Piper (pip install piper-tts), voice "alba" downloaded once into movie/voice-cache/
 * (git-ignored); MOVIE_VOICE=0 makes a silent movie. MOVIE_OUT: where the MP4 goes (default
 * tests/docs/movie/out/Pimcore-Supertext-Translation.mp4, git-ignored; the MP4 is not committed).
 * Needs ffmpeg.
 */
import { chromium } from 'playwright'
import { fileURLToPath } from 'node:url'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { MovieKit } from './movie/movie-kit.mjs'

const base = (process.env.BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '')
const here = (p) => fileURLToPath(new URL(p, import.meta.url))
const out = process.env.MOVIE_OUT ?? here('movie/out/Pimcore-Supertext-Translation.mp4')
const gif = here('../../docs/images/demo.gif')
const need = (name) => process.env[name] ?? (() => { throw new Error(`Set ${name}`) })()

const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {})
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' })

// Sign in as the demo editor (not recorded).
await page.goto(base + '/pimcore-studio/')
await page.waitForSelector('input[type=password]', { timeout: 120000 })
await page.locator('input[name=username]').fill(need('DEMO_EDITOR_EMAIL'))
await page.locator('input[type=password]').fill(need('DEMO_EDITOR_PASSWORD'))
await page.keyboard.press('Enter')
await page.getByText('Document Tree').first().waitFor({ timeout: 60000 })
await page.waitForTimeout(2500)

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

// Open the English home page and fold the document tree away, so the caption bar never covers
// the toolbar's Supertext button (not recorded).
await page.getByText('en', { exact: true }).first().dblclick()
await frameText('Welcome')
await page.locator('.flexlayout__border_button').first().click() // the Document Tree icon folds the tree
await park()
await page.waitForTimeout(1500)

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pimcore-movie-'))
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') })

// 1. The English page.
await kit.say("Here's a page in English, in Pimcore Studio.", { caption: 'A page in English' })

// 2. The Supertext button in the bottom toolbar → the dialog.
const button = page.locator('.supertext-translate-button').last()
await kit.say('In the toolbar at the bottom, click Translate with Supertext.', {
  caption: 'Click <b>Translate with Supertext</b>',
  during: async () => {
    await kit.click(button)
    await park()
    await page.locator('.supertext-targets').first().waitFor({ timeout: 30000 })
    await kit.sync()
  },
})
const modal = page.locator('.supertext-modal')
await kit.say('Keep German and French, and untick Italian.', {
  caption: 'Choose the languages: <b>German</b> and <b>French</b>',
  during: async () => {
    await kit.hold(700)
    await kit.click(modal.locator('.supertext-targets label', { hasText: 'Italian' }).first())
    await park()
  },
})
const submit = modal.locator('.supertext-submit')
await kit.say('Then click Translate.', { caption: 'Click <b>Translate</b>', during: () => kit.moveTo(submit), after: 0 })

// The stand-in answers at once: send the request only after the progress state has been seen.
let release
const released = new Promise((resolve) => (release = resolve))
await page.route(/\/api\/supertext\/elements\/.+\/translate/, async (route) => {
  if (route.request().method() === 'POST') await released
  await route.continue()
})
await page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])
await kit.say('Supertext translates the content.', {
  caption: 'Supertext translates the content…',
  during: async () => {
    await submit.click()
    await park()
    await kit.hold(1300)
    release()
  },
})
kit.pause()
await modal.locator('.supertext-results').waitFor({ timeout: 300000 })
await page.waitForTimeout(500)
kit.resume()
await kit.sync()

// 3. The result: open the German page.
await kit.say('A few seconds later, the German and French pages are there, saved unpublished.', {
  caption: 'Done: German and French pages, <b>unpublished</b>',
})
const german = modal.locator('.supertext-results .ant-alert').filter({ hasText: 'German' }).first().getByRole('button')
await kit.say("Let's open the German page and check it.", {
  caption: 'Click <b>Open</b> to check the German page',
  during: () => kit.moveTo(german),
})
await page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])
await german.click()
await page.waitForTimeout(350)
await park()
kit.pause()
await frameText('Willkommen')
await page.waitForTimeout(800)
kit.resume()
await kit.sync()
await kit.say('The German version is ready. Review it, and publish it as usual.', { caption: 'The German version is ready – review it and publish' })
await kit.caption('')

fs.mkdirSync(path.dirname(out), { recursive: true })
await kit.finish(out, { system: 'Pimcore', repo: 'Pimcore-Supertext-Translation', gif })
await browser.close()
fs.rmSync(workDir, { recursive: true, force: true })
console.log(`Saved ${out} and ${gif} (${(fs.statSync(gif).size / 1e6).toFixed(2)} MB)`)
