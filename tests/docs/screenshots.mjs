#!/usr/bin/env node
/**
 * Screenshots for docs/USER_GUIDE.md and docs/INSTALLATION.md, taken from the demo in a
 * headless browser. Run against a freshly started demo (sample content not yet translated)
 * that talks to the stand-in API (stand-in.mjs), so the dialogs show real Supertext output:
 *
 *   BASE_URL=http://127.0.0.1:8080 DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… \
 *   DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… npm run screenshots
 *
 * Writes docs/images/*.png at 1× scale, cropped to the relevant part.
 */
import { chromium } from 'playwright'
import { mkdirSync } from 'node:fs'

const base = (process.env.BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '')
const out = new URL('../../docs/images/', import.meta.url).pathname
mkdirSync(out, { recursive: true })
const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {})

const shot = async (page, name, target, pad = 0) => {
  const box = typeof target === 'string' ? await page.locator(target).last().boundingBox() : target
  const clip = { x: Math.max(0, box.x - pad), y: Math.max(0, box.y - pad), width: box.width + 2 * pad, height: box.height + 2 * pad }
  await page.screenshot({ path: out + name, clip })
  console.log('docs/images/' + name)
}
const modal = '.ant-modal-content'
const FIXED_DATE = Date.UTC(2026, 9, 1, 12) / 1000
const editorArea = { x: 380, y: 0, width: 970, height: 900 }

async function login (user, password) {
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 }, deviceScaleFactor: 1 })
  // The dialog's "Translated with Supertext on <date>" tags would change the images every day:
  // show a fixed date (1 October 2026), so CI only proposes new images when the UI changes.
  await page.route('**/pimcore-studio/api/supertext/elements/**', async (route) => {
    if (route.request().method() !== 'GET') return route.continue()
    const response = await route.fetch()
    const body = await response.json().catch(() => null)
    if (!body) return route.fulfill({ response })
    for (const l of body.languages ?? []) if (l.lastTranslation != null) l.lastTranslation = FIXED_DATE
    await route.fulfill({ response, json: body })
  })
  // Supertext → Settings shows the API address: show Supertext's live one, never the local stand-in.
  await page.addInitScript((live) => {
    const fix = () => document.querySelectorAll('.supertext-settings-status code').forEach((c) => { if (c.textContent !== live) c.textContent = live })
    new MutationObserver(fix).observe(document, { childList: true, subtree: true, characterData: true })
  }, 'https://api.supertext.com/v1/')
  await page.goto(base + '/pimcore-studio/')
  await page.waitForSelector('input[type=password]', { timeout: 120000 })
  await page.locator('input[name=username]').fill(user)
  await page.locator('input[type=password]').fill(password)
  await page.keyboard.press('Enter')
  await page.getByText('Document Tree').first().waitFor({ timeout: 60000 })
  await page.waitForTimeout(2000)
  return page
}

// Studio keeps every open editor tab in the page; click the Supertext button that is on top.
async function clickTranslateButton (page) {
  for (let attempt = 0; attempt < 60; attempt++) {
    for (const button of await page.locator('.supertext-translate-button').all()) {
      const box = await button.boundingBox()
      if (!box || box.width === 0) continue
      const onTop = await page.evaluate(([x, y]) => document.elementFromPoint(x, y)?.closest('.supertext-translate-button') !== null, [box.x + box.width / 2, box.y + box.height / 2])
      if (onTop) return button.click()
    }
    await page.waitForTimeout(1000)
  }
  if (process.env.DEBUG_SHOTS) await page.screenshot({ path: process.env.DEBUG_SHOTS })
  throw new Error('No Supertext button on screen')
}

async function openDialog (page) {
  await clickTranslateButton(page)
  await page.locator('.supertext-targets, .supertext-results').first().waitFor({ timeout: 30000 })
  await page.waitForTimeout(800)
}

// --- Editor: documents -------------------------------------------------------------
let page = await login(process.env.DEMO_EDITOR_EMAIL, process.env.DEMO_EDITOR_PASSWORD)
await page.getByText('en', { exact: true }).first().dblclick()
await page.waitForTimeout(3000)
await shot(page, 'document-toolbar.png', { x: 380, y: 830, width: 970, height: 60 })
await openDialog(page)
await shot(page, 'document-translate-dialog.png', modal)
await page.locator('.supertext-submit').click()
await page.locator('.supertext-results').waitFor({ timeout: 180000 })
await page.waitForTimeout(800)
await shot(page, 'document-translate-results.png', modal)
await page.locator('.supertext-results .ant-alert').first().getByRole('button').click()
await page.waitForTimeout(6000)
await shot(page, 'document-translation-german.png', editorArea)

// Overwrite warning: the German translation exists now.
await page.getByText('en', { exact: true }).first().dblclick()
await page.waitForTimeout(3000)
await openDialog(page)
await page.locator('.supertext-targets input[type=checkbox]').first().check()
await page.waitForTimeout(800)
await shot(page, 'document-overwrite-warning.png', modal)
await page.getByRole('button', { name: 'Cancel' }).last().click()
await page.waitForTimeout(1000)

// --- Editor: data objects ----------------------------------------------------------
await page.mouse.click(28, 873) // Data Object Tree
await page.getByText('Articles', { exact: true }).first().waitFor({ timeout: 30000 })
await page.mouse.click(97, 89) // expand "Articles"
await page.getByText('swiss-chocolate', { exact: true }).first().dblclick({ timeout: 30000 })
await page.waitForTimeout(3000)
await openDialog(page)
await shot(page, 'object-translate-dialog.png', modal)
await page.locator('.supertext-submit').click()
await page.locator('.supertext-results').waitFor({ timeout: 180000 })
await page.waitForTimeout(800)
await shot(page, 'object-translate-results.png', modal)
await page.getByRole('button', { name: 'Close' }).last().click()
await page.waitForTimeout(4000)
await page.mouse.click(577, 863) // language switcher: next language (de_CH)
await page.waitForTimeout(3000)
await shot(page, 'object-translation-german.png', { x: 380, y: 0, width: 970, height: 480 })
await page.close()

// --- Administrator: language setup and the permission ------------------------------
page = await login(process.env.DEMO_ADMIN_EMAIL, process.env.DEMO_ADMIN_PASSWORD)
await page.mouse.click(28, 69) // main menu
await page.getByText('System', { exact: true }).click()
await page.getByText('System Settings', { exact: true }).click()
await page.getByText('Localization & Internationalization (l10n/i18n)').click()
await page.waitForTimeout(2500)
await shot(page, 'languages.png', { x: 380, y: 40, width: 970, height: 660 })
await page.close()
page = await login(process.env.DEMO_ADMIN_EMAIL, process.env.DEMO_ADMIN_PASSWORD)
await page.mouse.click(28, 69)
await page.getByText('System', { exact: true }).click()
await page.getByText('User & Roles', { exact: true }).click()
await page.getByText('Roles', { exact: true }).first().click()
await page.waitForTimeout(2500)
await page.getByText('Editors', { exact: true }).first().click()
await page.waitForTimeout(2500)
await shot(page, 'role-permission.png', { x: 380, y: 40, width: 700, height: 390 })
await page.close()
page = await login(process.env.DEMO_ADMIN_EMAIL, process.env.DEMO_ADMIN_PASSWORD)
await page.mouse.click(28, 69)
await page.getByText('Supertext', { exact: true }).first().click()
await page.getByText('Settings', { exact: true }).last().click()
await page.locator('.supertext-settings-languages').waitFor({ timeout: 30000 })
await page.waitForTimeout(1500)
await shot(page, 'settings.png', '.supertext-settings', 8)
await page.close()
await browser.close()
