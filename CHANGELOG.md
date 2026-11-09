# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/).

## Unreleased

### Added

- **Translation review** on/off per language on *Supertext → Settings* (a checkbox next to the Supertext language): translations into a language with *Translation review* ticked go through Supertext's review service (Supertext Localization Review), where customers and Supertext linguists correct them on the website with a Chrome extension; corrections come back into Pimcore with the next translation, and texts translated before keep their (reviewed) wording. Same API key, billing, languages and messages; other languages keep going directly to Supertext. New setting `review_url` (defaults to Supertext's review service; `''` turns review off everywhere), environment variable `SUPERTEXT_REVIEW_URL`, override `languages.<language>.review`. All four interface languages.
- Requests to Supertext name the bundle's version in the User-Agent (`Pimcore-Supertext-Translation/<version>`).
- **Supertext → Settings** in Pimcore Studio's main navigation: the installed version (linked to its release), the API key status with the account and API key links, the API address, and the **language mapping as a select per Pimcore language** (Supertext's languages as *Name (code)*, plus *Formal or informal* where Supertext offers it). Each select is preselected with the saved choice, else the exact Supertext code or Supertext's default (`de` → `de-DE`), else *— choose —*. Administrators save it (stored in Pimcore's settings store); `supertext_translation.languages.<language>.code`/`.politeness` in the configuration still work and win (the row is then shown disabled), so existing setups need no change. Studio API `POST /supertext/settings/languages`. All four interface languages.
- Export for translation (XLIFF 1.2 or HTML) and import of translated files; Supertext SDK 0.5.0. The *Translate with Supertext* dialog has two more modes: *Export for translation* downloads the document's or object's texts as `pimcore-document-<id>-<language>.xlf` or `.html`, *Import translation* uploads a translated file (up to 10 MB, read in memory) and writes it back like a translation with Supertext (unpublished translation or new version), with a summary per element and language: imported, skipped because the source changed, not translated, formatting changed, not in the file, kept. Studio API `GET /supertext/elements/{type}/{id}/export` and `POST /supertext/import`.
- Tested on PHP 8.5 as well as 8.4 (Pimcore 2026.3 supports both; the bundle already allowed 8.5).
- French and Italian interface (and German where it was missing): the Studio button, dialog, permission label and the messages from the server (Supertext errors, permissions, missing parent page) follow the user's Studio language.
- First version for Pimcore 2026 with Pimcore Studio. All Supertext API calls go through the official Supertext PHP SDK, shipped inside the bundle (nothing extra to install).
- Its own message for an unsupported language pair, naming the codes ("Supertext does not support this language pair (en → xx-YY)"), in English, German, French and Italian.
- **Translate with Supertext** button in the toolbar of documents (pages, snippets, e-mails) and data objects, with a dialog: languages to translate into, "Already translated" / "Translated with Supertext on …" per language and an explicit *Overwrite existing translations* option.
- Documents: title, description, navigation name and title, and the texts of input, textarea, WYSIWYG and link editables are translated. Missing translations are created unpublished under the parent page's translation and linked to the original, with keys made from the translated navigation name; existing ones get a new version.
- Data objects: localized input, textarea and WYSIWYG fields, from any language that has text, saved as a new version.
- Permission *Translate with Supertext*; Pimcore's workspaces and language restrictions apply.
- Every translation is recorded as a note (type `supertext`) on the original.
- Settings: `SUPERTEXT_API_KEY` (with or without the `Supertext-Auth-Key` prefix), `SUPERTEXT_API_URL`, and in `supertext_translation` YAML the environment, timeout, Supertext language code and form of address per language, and the translated field and editable types.
- Links to create a Supertext account and to generate the API key in the dialog when no key is set, in messages for a rejected key, in `supertext:check` and in the installation guide.
- Console commands `supertext:translate` and `supertext:check` (which also shows the installed version).
- Retries when the Supertext API answers HTTP 429 (rate limit).
- English and German Studio strings.
- Demo for Railway (`demo/`) with demo accounts, an Editors role, four languages, sample pages and an article created on every start.
- Support: the Supertext support form is linked in the README, the user guide and GitHub's "New issue" page.
- Docs: a demo movie at the top of the README (`docs/images/demo.gif`) and a voiced install and configuration movie, recorded from the demo against the stand-in API (`npm run movie`, `npm run movie:install` in `tests/docs`); the installation guide shows *Supertext → Settings*.

### Fixed

- `supertext:check` no longer fails with a type error when it checks the API key without a site URL (e.g. on a server whose domain isn't set in Pimcore's system settings).

### Changed

- The Studio plugin is built against Pimcore Studio UI 2026.3.4 with Module Federation 2, the same Module Federation version Pimcore Studio 2026.3 uses (was 0.13); this also removes the security advisories in the build tools. Still React 18.3, as shared by Studio.
- Supertext SDK 0.5.0. Since 0.4.0, a target language without a mapping or an exact Supertext code uses its obvious default instead of being *not mapped*: a bare `de`/`en`/`fr`/`it`/`pt` → `de-DE`/`en-US`/`fr-FR`/`it-IT`/`pt-PT`, `zh_CN` → `zh-Hans`, `es_ES` → `es`. Languages without a default (`de_LI`, `en_IE`, `fr_BE`) still need a mapping.
- **Languages are no longer guessed.** A Pimcore language whose code is a Supertext code (`de_CH` → `de-CH`, `fr_CH`, `es`, `zh_Hans`, apart from case and `_`/`-`) is used as it is; every other language needs a Supertext code in the language mapping (`supertext_translation.languages.<language>.code`, e.g. `en: { code: en-GB }`). There are no default regions any more: they were wrong for most languages (Supertext wants `es`, `sl`, `nl` without region, `zh-Hans` instead of `zh-CN`, and rejects bare `de`). The same mapping gives the source language (bare: `en-GB` → `en`). An unmapped language shows *No Supertext language* in the dialog and can't be selected; translating into it reports *has no Supertext language yet* and sends nothing. `supertext:check` lists the mapping and marks unmapped languages.
- A formal/informal tone configured for a language Supertext offers none for (English, Swiss German, Romansh, Albanian, Serbian) is no longer sent: the dialog marks the language *No formal/informal tone*, and translating into it reports *Supertext offers no formal or informal tone for this language*.
- `POST /supertext/settings/test` returns the API key check (`{ok, check, message}`, always HTTP 200 for administrators).
- Bundled Supertext SDK 0.2.1 (commit `eeba8fa`, `V1` namespace): the API's language list, the key check and the version reports.
- Version reports, the API key check result and the reachability ping now use Supertext Plugin Health 0.1.0 (bundled in `lib/supertext-plugin-health`); Supertext SDK updated to 0.3.0. No change for sites: settings and stored state stay as they are.
- Studio plugin rebuilt against `@pimcore/studio-ui-bundle` 2026.3.2 (was 2026.3.1).

### Added

- Automatic API key check (Supertext's cost-free `features` call): when the bundle runs with a key it hasn't checked yet, when Supertext rejects the key during a translation, and on `supertext:check`. Administrators see the latest result with its time at the bottom of the translate dialog (*Supertext status*), together with unmapped languages and the version information setting, in English, German, French and Italian.
- *Send version information to Supertext* (`version_reports`, on by default; `SUPERTEXT_VERSION_REPORTS=0` turns it off): after an update and when the key check result changes, the bundle sends the site URL, the bundle, Pimcore, PHP and SDK versions, whether a key is set, the key check result and the ping address to the Supertext Commando Center, after the response. No content, no API key and no personal data. `supertext:check` sends a `check` report.
- Public ping endpoint `GET /supertext/ping` that only echoes Supertext's challenge, so Supertext can check the site is reachable (404 with version information off).
- The bundle keeps this state in Pimcore's settings store (scope `supertext_translation`).
- New messages *The language … has no Supertext language yet* and *Supertext offers no formal or informal tone for this language* in all four interface languages.
