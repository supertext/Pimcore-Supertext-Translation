# Working on this repository

Part of Supertext's "translation plugins for the top 20 open source CMS" project. Each CMS has its own repo named `Supertext/<CMS>-Supertext-Translation`. This one is the **Pimcore 2026** bundle (PHP, Composer package `supertext/pimcore-supertext-translation`, namespace `Supertext\PimcoreTranslationBundle`, with a Pimcore Studio UI plugin).

## Documentation rule (always)

Every plugin repo keeps three guides, and **every change that affects behaviour, settings, installation or the code structure updates them in the same commit**:

| File | Audience | Must cover |
| --- | --- | --- |
| `docs/INSTALLATION.md` | Administrators | Requirements, install/update/uninstall, API key, language setup, all settings, troubleshooting |
| `docs/USER_GUIDE.md` | Editors | How to translate and review in the CMS's own UI, what is and isn't translated, what errors mean |
| `docs/DEVELOPER.md` | Developers | Architecture, Supertext API protocol, local setup, tests, CI/deploy, releasing, known limitations/roadmap |

Also: `README.md` stays a short overview linking the three guides, and `CHANGELOG.md` gets an entry under *Unreleased* for every user-visible change. Before finishing any task, check the docs still match the code.

## Supertext account and API key links (always)

Everywhere an administrator enters or is told about the API key — the settings field's help text, the "no API key" / "authentication failed" messages, `docs/INSTALLATION.md`, `README.md` and the demo's `.env.example` — show both links (same as the WordPress plugin):

- Create a Supertext account (or log in): https://www.supertext.com/person/en/account/signin
- Generate the AI API key: https://www.supertext.com/en/integrations/api (supertext.com → Integrations → API; requires the **Admin** role)

Wording: "No Supertext account yet? Create one at supertext.com. Generate your API key at supertext.com → Integrations → API (requires the Admin role)." In the UI, links open in a new tab (`target="_blank" rel="noopener"`); where the CMS shows plain text only, use the bare URLs. New screens or messages that mention the key get the links too.

## UI languages (always)

The plugin's own UI (buttons, panels, dialogs, settings, permissions, messages) is available in English, German, French and Italian through the CMS's own translation mechanism, so it follows the user's back-end language. New or changed strings get all four languages in the same commit. Formal address (Sie, vous, Lei), the CMS's own terms in each language, "Supertext", placeholders and URLs never translated.

## Plugin list (always)

`README.md` ends with the shared list of all Supertext plugins (between the `<!-- supertext-plugins:start -->` and `<!-- supertext-plugins:end -->` markers). It is identical in every Supertext plugin repo: when a plugin is added, renamed or its description changes, update the list in **all** repos, not just this one.

## Releases (always)

Releases are published by `.github/workflows/release.yml`: never tag or create a GitHub release by hand. To release, follow `docs/DEVELOPER.md` → *Releasing* (new version section in `CHANGELOG.md`, same number in the version files) and push to `main`. A push without a new version releases nothing.

## Repo setup (always)

Every Supertext plugin repo has, and a new one gets from the start:

- `LICENSE` matching the license its manifest declares (`composer.json`, `package.json`, `pyproject.toml`, `.csproj`, plugin header).
- `SECURITY.md`: report vulnerabilities privately through GitHub's private vulnerability reporting or support@supertext.com, never in public issues.
- `.github/dependabot.yml`: weekly updates for its package ecosystem and GitHub Actions, minor and patch updates grouped into one pull request.
- `.github/workflows/checks.yml` (actionlint + zizmor on every push and PR, dependency review on PRs) and `.github/workflows/links.yml` (lychee weekly and on docs changes; broken links open the issue "Broken links in the docs"). Third-party actions are pinned to commit SHAs.
- PHP repos: PHPStan in CI (`phpstan.neon`, baseline in `phpstan-baseline.neon`).
- On GitHub: the About box filled in (one-sentence description, website https://www.supertext.com, topics), `main` protected against force-pushes and deletion, Wiki and Projects off, Dependabot alerts and private vulnerability reporting on, secret scanning with push protection and CodeQL default setup on, and the Supertext social preview image.
- A row in the plugin list (see *Plugin list*) and in the org profile (`Supertext/.github` → `profile/README.md`).

Claude sessions can't change GitHub repo settings (HTTP 403): add a new repo to Remy's setup script (`set-github-about`) instead of trying.

## Checks and alerts (always)

Before starting work in a repo, look at its open findings and fix what the task touches or what is quick: code scanning alerts (`gh api 'repos/Supertext/<Repo>/code-scanning/alerts?state=open'`), secret scanning alerts (`…/secret-scanning/alerts?state=open`), open Dependabot PRs and the issue "Broken links in the docs". New workflows and workflow changes must pass actionlint and zizmor; PHP code must pass PHPStan at the repo's level. See `docs/DEVELOPER.md` → *Code quality and security checks*.

## Demo accounts rule (always)

Every demo must be usable right after deployment, without anyone registering in a browser. On **every start**, the demo creates these accounts if they don't exist yet:

| Variables | Account |
| --- | --- |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | Full administrator (for Supertext staff) |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Editor-level account that can translate content in every demo language; used for automated tests and screenshots. Where the CMS has no editor role that works out of the box, use the closest role and document it. |

- Existing accounts are never modified: no password resets from variables, no duplicates on restart.
- A password that doesn't meet the CMS's own password rules skips that account with a clear warning in the log. The demo still starts.
- Values live only in the hosting platform's variables (Railway). Never in the repo, in chat or in logs. Log the variable name, never the password.
- If the CMS has a first-run "create admin" screen, these accounts replace it. Document that once `DEMO_*` is set, the screen no longer appears.
- If a demo already used CMS-specific names (e.g. `TYPO3_ADMIN_*`, `PAYLOAD_ADMIN_*`), keep them as fallbacks for `DEMO_ADMIN_*`.
- The demo also seeds its target languages and at least one sample entry in the source language, and makes sure the editor account can access every target language.
- Document the variables in `docs/DEVELOPER.md` (demo section) and in the demo's `.env.example`.

## Screenshots rule (always)

The user guide and installation guide of every plugin include screenshots of the real UI: at least the translate action before and after translating, a translated result, the overwrite or retranslate warning if there is one, the plugin's settings or configuration screen, and the CMS's language setup. Screenshots are taken from the repo's own demo with the headless browser, by a committed script (e.g. `npm run docs:screenshots`), against a stand-in API that returns real translations for the sample content, so the guides never show placeholder text. Use no real customer data, no secrets, no local URLs (show the live API endpoint). Keep the images small (1× scale, cropped to the relevant part), store them in `docs/images/`, give each one descriptive alt text, and regenerate them in the same commit whenever the UI they show changes.

## Shared Supertext protocol

AI file translation API v1, same as the WordPress plugin: POST HTML file → poll status → GET translation → DELETE. Details in `docs/DEVELOPER.md`. Never commit API keys; use the `SUPERTEXT_API_KEY` environment variable.

Lessons from the live API, apply them here: header `Authorization: Supertext-Auth-Key <key>` (strip a pasted prefix), retry HTTP 429 (per-second rate limit), and keep a whole text in one `data-st-id` element (each one is translated on its own). **Languages:** only the codes Supertext's `GET features` lists (the SDK's `Languages`, `…\Sdk\V1`): bare sources (`en`); targets regional only for de/en/fr/it/pt (`de-CH`, `en-US`), `zh-Hans`/`zh-Hant`, `sr-Latn`/`sr-Cyrl`, `gsw-u-sd-chzh`/`chbe`, bare for the rest (`es`, `nl`, `sl`). **Defaults only where obvious:** the plugin's mapping (a select per Pimcore language on Studio's *Supertext → Settings*, `assets/js/src/components/settings-page.tsx`, preselected with `Languages::suggestTarget()`, stored by `src/LanguageStore.php`, precedence in `src/Api/LanguageMapping.php`; `supertext_translation.languages.<language>.code` overrides it), else an exact match, else the SDK default (de→de-DE, en→en-US, zh_CN→zh-Hans, es_ES→es …), else the error `unsupported_language` (de_LI, en_IE, fr_BE need a choice); see `docs/DEVELOPER.md` → *Language codes*.

**Commando Center:** version reports, the API key check and the public ping (`/supertext/ping`) go through Supertext Plugin Health's `InstallationReporter` (bundled in `lib/supertext-plugin-health`, namespace `Supertext\PimcoreTranslationBundle\PluginHealth`; key check: the SDK's `checkApiKey()`) (`src/Api/InstallationReports.php`, Pimcore side `src/Reporting/Reporting.php`, state in Pimcore's settings store). Never let a local run report to the live center: set `SUPERTEXT_REPORT_URL` to a stand-in. See `docs/DEVELOPER.md` → *Commando Center*.

## This repo

- Before committing: `vendor/bin/phpunit`, PHP lint (`find src tests demo/project/src -name '*.php' | xargs -n1 php -l`), and for Studio changes `cd assets && npm run check-types && npm run build` (commit `public/build/`; the old build folder is replaced). CI also runs PHPStan (level 5, `phpstan analyse` after `composer install`; see `docs/DEVELOPER.md` → *Code quality and security checks*), builds the demo image and runs `tests/demo-check.sh` against MySQL, OpenSearch and the stand-in.
- Pimcore 2026 needs PHP 8.4+ and a product key (free Community Edition): `PIMCORE_ENCRYPTION_SECRET`, `PIMCORE_INSTANCE_IDENTIFIER` and `PIMCORE_PRODUCT_KEY` belong together. They live only in Railway variables and GitHub secrets.
- Test UI changes in the demo (see `docs/DEVELOPER.md` → Local development) and regenerate the screenshots they affect (`tests/docs/screenshots.mjs`).
- Movies (`docs/DEVELOPER.md` → *Demo movie*): `tests/docs/movie.mjs` (`npm run movie`) records the README's `docs/images/demo.gif` and a voiced MP4, `tests/docs/movie-install.mjs` (`npm run movie:install`) the install and configuration movie (MP4 only); both with the kit in `tests/docs/movie/`, against a fresh demo and the stand-in. Re-record them when the screens they show change; MP4s aren't committed.
- *Translation review* per language: `review` in the settings screen's mapping (checkbox on *Supertext → Settings*, `LanguageMapping::review()`, `languages.<language>.review` overrides it); `review_url` / `SUPERTEXT_REVIEW_URL` (default `SupertextClient::REVIEW_URL`, `''` = off) feeds `SupertextClient::forReview()`, used only by `SegmentTranslator` (never for the key check, reports or translation files).
- New settings go in `src/DependencyInjection/Configuration.php` (read through `src/Settings.php`) **and** the settings table in `docs/INSTALLATION.md`.
- Export for translation / Import translation (XLIFF and HTML, SDK `TranslationFiles`): core in `src/Api/FileExchange.php` (no Pimcore classes; reference `<document|object>:<id>:<source>:<target>`, plugin `Pimcore-Supertext-Translation`), glue in `src/Service/TranslationFileElements.php`, Studio panels in `assets/js/src/components/translation-files.tsx`; see `docs/DEVELOPER.md` → *Translation files*.
- Field rules live in `src/Service/DocumentTranslator.php` (`units`) and `src/Service/ObjectTranslator.php` (`fields`); keep "Field rules" in `docs/DEVELOPER.md` and "What is translated" in `docs/USER_GUIDE.md` in sync.
- Studio strings: `translations/studio.{en,de,fr,it}.yaml` (all four for every string). API messages are English and carry a `key` the dialog shows as `supertext.error.<key>` (see `docs/DEVELOPER.md` → Conventions).
- Keep `src/Api/` free of Pimcore and Symfony classes (unit tests run without Pimcore).
- All Supertext API calls go through the Supertext PHP SDK, shipped in `lib/supertext-sdk` with its namespace changed to `Supertext\PimcoreTranslationBundle\Sdk` and wrapped by `src/Api/SupertextClient.php`. Never edit `lib/supertext-sdk`: fix the SDK in `Supertext/Supertext-API-SDK-and-Guide` and run `scripts/vendor-sdk.sh`. Every SDK error code needs a key in `SupertextClient::KEYS` and `supertext.error.<key>` in all four Studio translation files. Supertext Plugin Health (version reports, key check result, ping) is shipped the same way in `lib/supertext-plugin-health` (namespace `Supertext\PimcoreTranslationBundle\PluginHealth`): never edit it, fix it in `Supertext/Commando-Center-Supertext-Translation` → `plugin-health/` and run `scripts/vendor-plugin-health.sh <tag>`. `demo/stage-module.sh` and the demo Dockerfile copy `lib/`.
- The bundle name `SupertextTranslationBundle`, the permission `supertext_translate`, the note type `supertext` and the API routes under `/pimcore-studio/api/supertext/` are stored in or used by users' installations; renaming them is a breaking change.
- End-to-end tests against the real Supertext API: `.github/workflows/e2e.yml` with `tests/e2e/` (see "End-to-end tests" in `docs/DEVELOPER.md`). The key is only the GitHub secret `SUPERTEXT_API_KEY`, set by Remy; sessions don't use a live key. Behaviour changes need matching checks in `tests/e2e/live.mjs`.
- `demo/` is the Railway demo (`railway.json` → `demo/Dockerfile`, context = repo root; the bundle is copied to `demo/module`, see `demo/stage-module.sh`). Demo-only setup is `demo/project/src/Command/DemoSetupCommand.php` (`supertext:demo-setup`); it only creates what's missing. The class `Article` is defined in `demo/project/config/pimcore/classes/`. Demo secrets live only in Railway variables.
