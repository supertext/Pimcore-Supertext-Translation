# Developer guide — Supertext Translation for Pimcore

For developers who work on the bundle, its demo or its CI. Administrators find setup in the [installation guide](INSTALLATION.md), editors their part in the [user guide](USER_GUIDE.md).

## Architecture

A Symfony bundle (`Supertext\PimcoreTranslationBundle`) with a Pimcore Studio UI plugin. The bundle adds API endpoints to Studio's backend; the plugin adds the toolbar button and the dialog that call them, and the *Supertext → Settings* screen.

| Path | Purpose |
| --- | --- |
| `src/SupertextTranslationBundle.php` | The bundle (`AbstractPimcoreBundle`, installer from the container) |
| `src/Installer/Installer.php` | Adds (and on uninstall removes) the permission `supertext_translate` in the category *Supertext* |
| `src/DependencyInjection/` | Configuration tree `supertext_translation` (see the settings table in the installation guide) |
| `src/Settings.php` | Reads the configuration and `SUPERTEXT_API_KEY` / `SUPERTEXT_API_URL` / `SUPERTEXT_REVIEW_URL` / `SUPERTEXT_VERSION_REPORTS` / `SUPERTEXT_REPORT_URL` (`Settings::env()`: `$_SERVER`/`$_ENV` from Symfony's `.env` files, else the process environment), the language mapping (`languageMapping()`: the settings screen's choices from `LanguageStore`, overridden by the configuration), builds the API client and the SDK transport (Symfony HttpClient), the account and API key links |
| `src/Api/` | Wrapper around the Supertext SDK (`SupertextClient` with `forReview()` for *Translation review*, `CallableTransport`), language codes (`LanguageCodes`), the language mapping of the settings screen (`LanguageMapping`: precedence, the screen's rows, validation of a save), the Commando Center glue (`InstallationReports`, `ReportTransport`), exception. No Pimcore or Symfony classes: unit-tested on their own |
| `src/LanguageStore.php` | The settings screen's language mapping in Pimcore's settings store (`settings_store`, scope `supertext_translation`, id `languages`, JSON `{<language>: {code, politeness, review}}`) |
| `src/Reporting/Reporting.php` | The Pimcore side of the Commando Center: state in the settings store, site URL, Pimcore version, runs reporter actions after the response (`kernel.terminate`, `console.terminate`) |
| `src/Ping/PingController.php` | The public `GET /supertext/ping` (outside Studio's prefix and firewall) |
| `lib/supertext-sdk/` | Bundled copy of the Supertext PHP SDK, namespace `Supertext\PimcoreTranslationBundle\Sdk` (API version 1 in `…\Sdk\V1`; see *Supertext SDK*) |
| `lib/supertext-plugin-health/` | Bundled copy of Supertext Plugin Health (PHP), namespace `Supertext\PimcoreTranslationBundle\PluginHealth` (see *Supertext Plugin Health*) |
| `src/Service/SegmentTranslator.php` | Sends a set of texts for one language pair, in chunks below the API's size limit |
| `src/Service/DocumentTranslator.php` | Documents: texts of a page, creating or updating its linked translation |
| `src/Service/ObjectTranslator.php` | Data objects: the localized fields of one language into others |
| `src/Service/History.php` | One `Element\Note` (type `supertext`) per translation, on the source element; also the "Translated with Supertext on" dates |
| `src/Controller/SupertextController.php` | Studio API endpoints (below) |
| `src/Api/FileExchange.php`, `src/Api/FileElements.php`, `src/Service/TranslationFileElements.php` | *Export for translation* / *Import translation* (XLIFF 1.2 and HTML files, SDK `TranslationFiles`; see *Translation files*) |
| `src/Command/` | `supertext:translate`, `supertext:check` (also prints the installed version) |
| `src/Webpack/WebpackEntryPointProvider.php` | Registers the plugin's build (`public/build/*/entrypoints.json`) with Studio |
| `assets/js/src/` | The Studio plugin (React, TypeScript, Module Federation via rsbuild): `index.ts` registers the buttons in the document and data object editor toolbars, `components/translate-modal.tsx` is the dialog (modes *Translate with Supertext*, *Export for translation*, *Import translation*), `components/translation-files.tsx` the export and import panels, `components/settings-page.tsx` the *Supertext → Settings* widget (registered in the main navigation with `MainNavRegistry` and `WidgetRegistry`, icon `supertext-globe` from `icons/`), `api.ts` the fetch calls |
| `public/build/<hash>/` | The built plugin, committed (installs need no Node.js) |
| `translations/studio.{en,de,fr,it}.yaml` | Studio strings (dialog, permission label, `supertext.error.*` for the messages from the API), in English, German, French and Italian |
| `config/` | Services, the route imports (`config/pimcore/routing.yaml`: the Studio API with Studio's prefix, the public ping without) |

**Studio API** (session authentication, under `/pimcore-studio/api`):

| Method and path | |
| --- | --- |
| `GET /supertext/elements/{document\|data-object}/{id}` | Languages and their state for the dialog: exists / has content, editable, parent ready (documents), last Supertext translation, `supertext` (the target code; `null`: not mapped), `toneUnavailable`; whether a key is set; the account and key links; for administrators `status: {check, reports, reportsFromEnvironment}` |
| `POST /supertext/elements/{type}/{id}/translate` | `{source?, targets[], overwrite}` → `{results: [{language, status: translated\|skipped\|error, message, documentId?, path?, created?}]}` |
| `GET /supertext/elements/{type}/{id}/export` | `?targets=de_CH,fr_CH&format=xliff\|html&source=en&overwrite=1` → the translation file (`Content-Disposition: attachment`), or a JSON error (`nothing-to-translate`, `html-one-language`, `choose-target`, …) |
| `POST /supertext/import` | multipart `file` (≤ 10 MB), `includeChanged`, `overwrite`; header `X-Supertext-Request: 1` → `{jobs: [{reference, type, id, language, error: {message, key, params}\|null, counts: {imported, sourceChanged, untranslated, invalidMarkup, missing, kept}}]}` |
| `GET /supertext/settings` | Version and release link, API address, key status, `status` (latest key check, version reports), `languages`: per Pimcore language `saved` (the screen's code, `''` if none), `override` (the configuration's, or `null`), `suggested` (`Languages::suggestTarget()`), `code` (what a translation sends; `null`: not translatable), `politeness`, `politenessOverride`, `politenessSupported`, `review`, `reviewOverride`; `reviewAvailable` (`false`: `review_url` is empty); `targets`: Supertext's targets (`Languages::targetOptions()`) with `localName` (PHP intl, the user's language), `isAdmin` |
| `POST /supertext/settings/languages` | Administrators, header `X-Supertext-Request: 1`: `{languages: {<language>: {code, politeness, review}}}`, validated by `LanguageMapping::validate()` (only Pimcore's languages, only Supertext's codes, a tone only where offered; else 400 `invalid-mapping`), saved by `LanguageStore` → the same as `GET /supertext/settings` |
| `POST /supertext/settings/test` | The SDK's key check (cost-free), stored and reported (`testConnection`) → `{ok, check: {status, checkedAt, httpStatus, errorCode}, message}`; always 200 for administrators |

Each requires the permission `supertext_translate` and *view* on the element; the services check the rest (save/create on target documents, `lEdit` languages on objects).

**Public route** (no login, no session): `GET /supertext/ping?challenge=<16–64 of A-Z a-z 0-9 _ ->` → 200 `{"ok":true,"plugin":"Pimcore-Supertext-Translation","challenge":"…"}`, 400 `{"ok":false}` for any other challenge, 404 with version reports off; `Cache-Control: no-store`.

**Documents.** A document's language is its `language` property (default: the first valid language). Its translations are Pimcore's linked translations (`Document\Service::getTranslations`). A missing translation is created with `Document\Service::copyAsChild($parentTranslation, $document, …, $language)` (which links it), unpublished, then its texts are replaced; an existing one is loaded at its latest version and saved with `saveVersion()` (not published), with missing editables copied from the source first. The source's latest version (drafts included) is translated.

**Data objects.** Values are read from the latest version of the object and written with `setLocalizedValue()`; all target languages go into one `saveVersion()` with a note in the version.

### Field rules

`DocumentTranslator::units()`:

| Unit | Rule |
| --- | --- |
| `meta:title`, `meta:description` | Page title and description (`Document\Page` only) |
| `property:navigation_name`, `property:navigation_title` | Text properties; written back as inheritable properties |
| `editable:<name>` | Editables whose type is in `document_editable_types` (default `input`, `textarea`, `wysiwyg`, `link`); WYSIWYG as HTML, link: the `text` only |

New keys: the target's code (`de-ch`) for pages directly under the root, otherwise `AsciiSlugger` of the translated navigation name or title, made unique. Existing translations keep their key.

`ObjectTranslator::fields()`: the children of the class's `localizedfields` whose field type is in `object_field_types` (default `input`, `textarea`, `wysiwyg`; WYSIWYG as HTML). Field collections, object bricks, blocks and classification stores are not included. A language "has content" if any of these fields has text in it (fallback values ignored).

Empty values (and HTML without text) are not sent.

## Supertext API protocol

Shared with the WordPress plugin and every other Supertext CMS plugin:

1. `POST {base}translate/ai/file`: multipart with `file` (part `Content-Type` exactly `text/html`, no charset, or the API answers 415), `target_lang` (a Supertext target code, e.g. `de-CH` or `es`), optional `source_lang` (bare language, e.g. `en`); see *Language codes*, optional `politeness` (`more`/`less`). Returns `{file_id}`.
2. `GET …/{file_id}/status` until `done` (`error`, `limit_exceeded`, `deleted` are terminal).
3. `GET …/{file_id}/translation` returns the translated HTML.
4. `DELETE …/{file_id}` (files also expire after 24 h).

Each text is one `<div data-st-id="n">` element in the document, so it is translated as a whole. Auth header: `Authorization: Supertext-Auth-Key <key>`. Supertext shows the key with the prefix, so the client strips a pasted `Supertext-Auth-Key ` and always sends exactly one. Base URLs: `https://api.supertext.com/v1/` (live), `https://api.staging.supertext.com/v1/`, `https://api.testing.supertext.com/v1/`. `GET features` is the cost-free key check. The User-Agent is `supertext-sdk-php Pimcore-Supertext-Translation/<version>` (`Settings::version()`, as in the reports).

**Translation review** (per target language: `review` in the settings screen's mapping, `languages.<language>.review` overriding it; `LanguageMapping::review()`, `Settings::review()`): `SegmentTranslator` takes `$client->forReview($settings->review($targetLanguage))`, a second SDK client with the same key, transport, timeouts and User-Agent whose base URL is `review_url` / `SUPERTEXT_REVIEW_URL` (default `SupertextClient::REVIEW_URL`, the translation proxy of [Supertext Localization Review](https://github.com/Supertext/Localization-Review-Supertext-Translation)), created once per client. Every translation is a full round trip in one call (upload, status, download, delete), so a job is always collected by the client that submitted it. The proxy speaks the same API v1, forwards to the live Supertext API with the plugin's key, stores every segment per key and serves reviewers' corrections on the next upload, so nothing else changes in the bundle. `review_url: ''` (or the same address as the API) means `forReview()` always returns the direct client and the screen's checkboxes are disabled (`reviewAvailable: false`). The key check (`Settings::client()->checkApiKey()`, the reporter), Commando Center reports and translation-file export/import never use the review service. Tests: `SupertextClientTest` (all four calls of a review language at the review URL with the same key and User-Agent, other languages and the key check direct, off with an empty or identical URL, the default), `SettingsTest` (default `REVIEW_URL`, `SUPERTEXT_REVIEW_URL` wins, `''` off), `LanguageMappingTest` (off by default, the configuration wins).

**Rate limit:** the API limits requests per second per key (HTTP 429). The client retries a 429 up to 4 times, waiting for `Retry-After` if sent, otherwise 1, 2, 4 and 8 seconds plus jitter. Target languages are translated one after the other.

## Supertext SDK

The protocol above, the retries, the language list and the `data-st-id` document come from the official Supertext PHP SDK ([Supertext/Supertext-API-SDK-and-Guide](https://github.com/Supertext/Supertext-API-SDK-and-Guide)). The bundle ships a copy in `lib/supertext-sdk/` with its namespace changed from `Supertext\Sdk` to `Supertext\PimcoreTranslationBundle\Sdk` (Composer autoloads it from the bundle's `composer.json`; `tests/bootstrap.php` too), so sites install nothing extra and another package that requires the SDK itself can't clash with it. `lib/supertext-sdk/COMMIT` names the SDK revision (currently 0.3.0). The SDK provides the API client and the API key check (`Client::checkApiKey()`, `Protocol::apiKeyFingerprint()`); version reports and the ping are Supertext Plugin Health's (see below). Everything that talks to the API is in `…\Sdk\V1` (`Client`, `Languages`, `Protocol`, `ApiKeyCheck`, …); only `…\Sdk\Supertext::SDK_VERSION` and the HTTP layer (`…\Sdk\Http`) are version-neutral. The vendor script copies the whole `sdk/php/src` tree and rewrites `Supertext\Sdk\` (sub-namespaces included).

- Never edit `lib/supertext-sdk/`. Fix the SDK in its repository, then run `scripts/vendor-sdk.sh [ref]` (default `main`; `SDK_DIR=/path/to/clone` uses a local clone) and the tests.
- `src/Api/SupertextClient.php` keeps the bundle's interface (`translateDocument`, `translateSegments`, `validateApiKey`, transport callable) and turns every SDK error code into a `SupertextException` with a `key` (`KEYS`) and `params`. A new SDK error code needs a key there and `supertext.error.<key>` in all four `translations/studio.*.yaml`.
- PHPStan analyses `src/` only; the SDK and Plugin Health have their own checks in their repositories.
- Segments missing from Supertext's answer are left untouched.
- The live API answers an unknown language (`xx-YY`) with HTTP 404 `INVALID_LANGUAGE_PAIR`; the SDK reports it as `invalid_language_pair` with both codes (key `invalid-language-pair`, params `source` and `target`).
- `unsupported_language` (a Pimcore language without a Supertext code; nothing sent) → key `unsupported-language`, param `language`; `unsupported_feature` (politeness for a target without it) → key `unsupported-feature`.

## Supertext Plugin Health

[Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health) is the plugin side of the Supertext Commando Center: version reports, the report of the API key check result and the answer of the public ping endpoint (`InstallationReporter`, `ReporterState`, `KeyCheck`, `PingResponse`). It has no dependencies and doesn't need the SDK: `src/Api/InstallationReports.php` passes the SDK's key check in (`CallableKeyChecker` with `Client::checkApiKey()` and `Protocol::apiKeyFingerprint()`) and `Supertext::SDK_VERSION` as `sdkVersion`. The bundle ships a copy in `lib/supertext-plugin-health/` with its namespace changed from `Supertext\PluginHealth` to `Supertext\PimcoreTranslationBundle\PluginHealth` (autoloaded like the SDK copy from `composer.json` and `tests/bootstrap.php`); `VERSION` and `COMMIT` there name the release (currently 0.1.0).

- Never edit `lib/supertext-plugin-health/`. Fix it in Commando-Center-Supertext-Translation → `plugin-health/`, then run `scripts/vendor-plugin-health.sh <tag>` (e.g. `plugin-health-v0.1.0`; default `main`; `PLUGIN_HEALTH_DIR=/path/to/clone` uses a local clone) and the tests.
- Its HTTP interface has the same shape as the SDK's; `src/Api/ReportTransport.php` sends the reports through the bundle's SDK transport (Symfony HttpClient), so both use Pimcore's proxy settings.
- Its stored state is the same JSON the SDK 0.1–0.2 reporter stored, so installations that update keep their history (no new `installed` report).

## Translation files

*Export for translation* and *Import translation*: an element's texts as an XLIFF 1.2 or HTML file, translated outside the API, and the translated file written back. File formats, checksums and the safety checks of imported files are the SDK's `Supertext\PimcoreTranslationBundle\Sdk\Files\TranslationFiles` (SDK 0.5.0, integration guide → *Translation files: XLIFF and HTML*); the bundle only collects, finds and writes back.

- **Core** (`src/Api/FileExchange.php`, no Pimcore or Symfony classes, `tests/unit/FileExchangeTest.php`): `export()` builds one SDK `FileJob` per target language from exactly the segments an API translation sends (`FileElements::segments()`: `DocumentTranslator::units()` of the latest version, keys `meta:title`, `property:navigation_name`, `editable:<name>`; for objects the localized field names), skipping languages that keep their translation (documents: a linked translation exists; objects: the language has text) unless *overwrite*. `import()` refuses files over 10,000,000 bytes, `read()`s the file and per job checks `plugin` (`Pimcore-Supertext-Translation`, else `file-other-system`), parses `reference` (else `file-not-found`), checks both languages are valid Pimcore languages (`file-no-language`), loads the element with *view* permission (`file-not-found`), checks the user may write the target (`file-not-allowed`), collects the current segments the same way and calls `importTranslations()`. `ok` results (and `source_changed` with *include changed*) are written in one go; `untranslated`, `invalid_markup` and `missing` are only counted; a kept target counts all of the file's segments as *kept*.
- **Reference** of a job: `<type>:<id>:<source language>:<target language>`, type `document` or `object`, e.g. `document:12:en:de_CH`, `object:7:en:fr_CH` (ids and Pimcore language codes, never titles; `FileExchange::reference()` / `parseReference()`). A document's source is its own language.
- **Languages in the file:** the Supertext codes of the mapping (`LanguageCodes::source()`/`target()` with `Settings::mapping()`); a language without one is written as its Pimcore code with `-`, so it never blocks the export.
- **Write-back:** `DocumentTranslator::writeTranslations()` and `ObjectTranslator::writeTranslations()` reuse the API path (`create()`/`update()` take the translations instead of calling Supertext): a new unpublished linked translation (parent translation required, key from the translated navigation name) or a new version, the Supertext note, permission checks (`parent-missing`, `create-not-allowed`, `edit-not-allowed`, `language-not-allowed`).
- **Studio:** the dialog's `Segmented` switches modes. Export fetches the file and saves it through a blob link (Studio's session cookie authenticates the fetch); import posts `FormData` with the header `X-Supertext-Request: 1`, which a cross-site form can't send (Studio has no CSRF token of its own for multipart requests). File names: `pimcore-<document|object>-<id>-<language>.xlf` / `.html`, `pimcore-<type>-<id>.xlf` for several languages.
- `tests/demo-check.sh` exports the sample article as the editor through the Studio API, fills one `<target>`, imports it and checks the summary and the Supertext note.

## Language codes

Supertext accepts exactly the codes its `GET features` lists (the SDK's `Languages::TARGETS`/`SOURCES`, October 2026): targets regional only for de/en/fr/it/pt (`de-CH`, `en-US`, `pt-BR`), `zh-Hans`/`zh-Hant`, `sr-Latn`/`sr-Cyrl`, `gsw-u-sd-chzh`/`chbe`, and **bare for all others** (`es`, `nl`, `sl`); sources are bare (`en`). Anything else is HTTP 404 `INVALID_LANGUAGE_PAIR` (bare `de`, `es-ES`, `sl-SI`, `zh-CN`). No pair within one language.

**No guessing beyond the SDK's defaults.** `src/Api/LanguageCodes.php` (the SDK's `Languages`; no CMS dependency, `tests/unit/LanguageCodesTest.php`) resolves a Pimcore language with **one mapping for both directions**, `Settings::mapping()` (`LanguageMapping::mapping()`: the codes saved on *Supertext → Settings*, overridden by non-blank `supertext_translation.languages.<language>.code`):

1. the mapping's value, if there is one (keys compared ignoring case and `_`/`-`; the value is sent as it is, even if the SDK doesn't list it);
2. else the Pimcore code if it equals a Supertext code apart from case and `_`/`-` (`de_CH` → `de-CH`, `es`);
3. else, for targets (since SDK 0.4.0), the SDK's default: `de` → `de-DE`, `en` → `en-US`, `zh_CN` → `zh-Hans`, `es_ES` → `es`, `nl_NL` → `nl` (nothing for `de_LI`, `en_IE`, `fr_BE`, `sr_RS`);
4. else `SupertextException` key `unsupported-language`, and nothing is sent for that language (the per-language result says so; the other languages are translated).

Sources use the same mapping in their bare form (`en` mapped to `en-GB` → `en`; `en_GB` → `en`). `Settings::languageCodeOrNull()` feeds the dialog (`supertext: null` → *No Supertext language*, not selectable), `GET /supertext/settings` and `supertext:check`.

**Settings screen** (`components/settings-page.tsx`): one antd `Select` per Pimcore language (Studio's own component, searchable, options *localized name (code)*), preselected with the saved code, else `suggested`, else empty (*— choose —*); a second `Select` for the tone only when the chosen target supports it; a `Checkbox` for *Translation review* (disabled while `reviewAvailable` is false). Saving sends every row (a preselected suggestion is then saved as the language's code); a configuration override disables the row. `tests/unit/LanguageMappingTest.php` covers precedence, rows and validation; `tests/demo-check.sh` saves through the endpoint.

**Politeness:** the tone is the configuration's `politeness`, else the screen's. `Settings::politenessFor()` sends `more`/`less` only where `Languages::supportsPoliteness()` isn't `false`; a tone configured for a target without one (English, Swiss German, Romansh, Albanian, Serbian) fails with `unsupported-feature` instead of being dropped, and the dialog marks the language *No formal/informal tone*.

The stand-in (`tests/docs/stand-in.mjs`) accepts exactly this list and rejects everything else like the real API.

## Commando Center: version reports, key check, ping

Protocol: [Commando-Center-Supertext-Translation](https://github.com/Supertext/Commando-Center-Supertext-Translation) → `docs/DEVELOPER.md` → *Report protocol*. Supertext Plugin Health's `InstallationReporter` and `pingResponse()` implement it, with the SDK's `checkApiKey()` as the key check; `src/Api/InstallationReports.php` (no Pimcore classes) loads/stores the reporter's state and builds the reporter, `src/Reporting/Reporting.php` supplies what Pimcore knows:

- **Setting:** `version_reports` (bundle config, default `true`); `SUPERTEXT_VERSION_REPORTS` wins (`InstallationReporter::reportsEnabled()`, read through `Settings::env()` because Symfony's Dotenv doesn't `putenv()`). Off: nothing is sent, the ping answers 404. `SUPERTEXT_REPORT_URL` sends reports elsewhere (tests, the demo check).
- **Report fields:** plugin `Pimcore-Supertext-Translation` (the GitHub repository name) with `Settings::version()` (Composer, the release tag); system `Pimcore` with `Pimcore\Version::getVersion()`; runtime PHP; `site_url` = `Tool::getHostUrl()` of the back-end request (commands: the URL last seen on a request, kept in the settings store as `site_url`, else Pimcore's system settings domain; without any, nothing is reported); `callback.url` = `<site>/supertext/ping`. Reports and the key check go through the bundle's Symfony HttpClient transport (`CallableTransport`, for reports wrapped in `ReportTransport`, passes the per-request timeout: 3 s for reports, 10 s for the check). `sdk_version` is the bundled SDK's `Supertext::SDK_VERSION`.
- **When:** the settings screen only holds the language mapping (the key, address and version reports are YAML and the environment), so there is no `afterSettingsSaved`. `Reporting::later()` queues an action and runs it on `kernel.terminate` (after the response is sent) or `console.terminate`: `onRequest` from every Studio endpoint of the bundle and `supertext:translate` (reports `installed`, `upgraded` or `api_key_check`; checks the key only when its fingerprint changed); `authenticationFailed` from `SegmentTranslator` when a translation gets HTTP 401/403 (replaces that request's `onRequest`). `POST /supertext/settings/test` runs `testConnection` and waits for it; `supertext:check` runs `testConnection` and then `reportCheck` (a `check` report). Nothing here throws into a request (`InstallationReports::run()` catches everything, also storage errors).
- **State:** Pimcore's settings store (`Pimcore\Model\Tool\SettingsStore`, table `settings_store`), scope `supertext_translation`: id `reporter` (the reporter's JSON state: last reported versions and key status, last failure, latest key check with the key's fingerprint), id `site_url` and id `languages` (the settings screen's mapping, `LanguageStore`). The key itself is never stored or logged; the fingerprint stays local.
- **Ping:** `src/Ping/PingController.php`, route `supertext_translation_ping`, imported without Studio's prefix, so no Studio firewall or session applies; Plugin Health's answer (`Cache-Control: no-store`) keeps it out of Pimcore's full page cache.
- **Display:** administrators get `status` with the element info and see it at the bottom of the translate dialog (key check with time and, for `invalid`/`missing`, the account and API key links; unmapped languages; version reports on/off with the help text). Strings `supertext.keycheck.*` and `supertext.status.*` (the center's texts) in all four `studio.*.yaml`. `supertext:check` prints the same in English.

## Local development

Pimcore 2026 needs PHP 8.4 or 8.5, MySQL 8 or MariaDB 10.11, OpenSearch 2 (Studio's search index), and a product key (free Community Edition, see [below](#pimcore-product-key)). The demo project in `demo/project` installs the bundle from `demo/module` (a Composer path repository), which `demo/stage-module.sh` fills.

```bash
cd assets && npm ci && npm run build && cd ..      # only after changing the plugin
demo/stage-module.sh
cd demo/project
composer install
docker run -d --name opensearch -p 9200:9200 -e discovery.type=single-node \
  -e DISABLE_SECURITY_PLUGIN=true -e DISABLE_INSTALL_DEMO_CONFIG=true opensearchproject/opensearch:2
cat > .env.local <<'ENV'
APP_ENV=dev
DATABASE_URL=mysql://pimcore:…@127.0.0.1:3306/pimcore?serverVersion=mariadb-10.11.14
PIMCORE_OPENSEARCH_DSN=opensearch://127.0.0.1:9200?ssl=false
PIMCORE_ENCRYPTION_SECRET=…
PIMCORE_INSTANCE_IDENTIFIER=…
PIMCORE_PRODUCT_KEY=…
APPLICATION_SECRET=…
MERCURE_JWT_KEY=…
SUPERTEXT_API_KEY=…
ENV
PIMCORE_ADMIN_USER=admin PIMCORE_ADMIN_PASSWORD='…' vendor/bin/pimcore-install --install-profile='App\Installer\SkeletonProfile' --no-interaction
DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD='…' bin/console supertext:demo-setup
bin/console messenger:consume pimcore_core pimcore_generic_data_index_queue &   # keeps the search index up to date
php -S 127.0.0.1:8090 -t public ../router.php   # serves files, everything else through public/index.php
# Studio: http://127.0.0.1:8090/pimcore-studio/
```

PHP's built-in server needs a router script that returns `false` for existing files; otherwise Studio's JavaScript is served through Symfony with the wrong type and Studio stays on its loading screen. In `dev`, the Symfony debug toolbar covers the bottom of Studio; use `APP_ENV=prod` for UI tests. If OpenSearch refuses writes because the disk is nearly full, turn off its disk watermark (`cluster.routing.allocation.disk.threshold_enabled: false`).

After changing the bundle, run `demo/stage-module.sh` and `composer update supertext/pimcore-supertext-translation` (or work with a symlinked path repository), then `bin/console assets:install public` and `bin/console cache:clear`.

To work without a real key, run the stand-in API (`node tests/docs/stand-in.mjs`) and set `SUPERTEXT_API_KEY=anything SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/ SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report` (the stand-in also answers version reports with 202, so a local install never reports to the live Commando Center). It returns real German, French and Italian for the demo's pages and article and `[de-CH] …`-prefixed text for anything else.

### Pimcore product key

Pimcore 2026 refuses to start without a registered instance: `PIMCORE_ENCRYPTION_SECRET` (generated), `PIMCORE_INSTANCE_IDENTIFIER` (chosen) and `PIMCORE_PRODUCT_KEY` (from <https://license.pimcore.com/register>, which takes the identifier and a hash of the secret) belong together. The Community Edition key is free for organisations below Pimcore's revenue limit. The demo, CI and local installs use the same registered instance; the three values live only in Railway's variables, GitHub's repository secrets and private `.env.local` files.

## Tests

```bash
phpunit            # or vendor/bin/phpunit after composer install
cd assets && npm run check-types && npm run build
```

- `tests/unit/SupertextClientTest.php`: the SDK wrapper: protocol, auth header and prefix, codes sent as given, 429 retries, error keys (language pair, unsupported tone included), clean-up, segments (plain text, HTML, blank, missing), splitting into several documents, the non-throwing key check, the review service client (`forReview()`: upload, status, download and delete at the review URL, other languages and the key check direct, off with an empty URL, `REVIEW_URL`).
- `tests/unit/LanguageCodesTest.php`: exact Supertext codes, only the SDK's default targets (`de` → `de-DE`; `fr_BE` is `unsupported-language`), the mapping (case and `_`/`-`, blank entries, unknown codes sent as they are), bare sources from the same mapping, politeness only where offered.
- `tests/unit/LanguageMappingTest.php`: the settings screen's mapping with the configuration winning, the rows (saved, suggested, override, `— choose —` for `de_LI`), validation of a save (Supertext's spelling, unknown codes and languages refused, tones only where offered).
- `tests/unit/SettingsTest.php`: the mapping from the bundle configuration and from the settings screen (a stub `LanguageStore`), tone per target, `version_reports` on by default and `SUPERTEXT_VERSION_REPORTS` winning, `SUPERTEXT_REPORT_URL`.
- `tests/unit/InstallationReportsTest.php` (fake transport, never the live API or center): one `installed` report with the right plugin, system, SDK version, site and callback URL and without the key; a state stored by the SDK 0.2 reporter kept as it is (no new report); nothing on later requests until the key changes; a 401/403 reported once; `testConnection` and the `check` report; nothing sent with reports off; the one-hour back-off; no site URL; storage errors never thrown; ping answers.
- `tests/unit/FileExchangeTest.php` (fake `FileElements`): reference round trip and file names, export with the services' keys and the mapping (unmapped languages as Pimcore codes, kept languages left out), HTML with one language, export errors, import with another plugin, an unknown reference or language, an element the user may not edit, `ok` written, `source_changed` only with the option, `untranslated`/`invalid_markup`/`missing` never written, kept translations, a write error, files too large, with a DTD or without metadata.
- `tests/unit/StudioTranslationsTest.php`: the four `studio.*.yaml` files have the same keys, `{{placeholders}}` and links, and every error key the PHP code sends has an English text.
- `tests/demo-check.sh` (CI): the demo image on MySQL and OpenSearch with the stand-in, started twice: demo accounts created once and never duplicated, no passwords in the log, the Editors role and the permission, `supertext:check`, the parent-page rule, translation of the sample pages and article as the editor into three languages (titles, slug keys, HTML with markup, link texts, unpublished pages, linked translations, notes), the skip on a second run, `supertext:check` (key check and language mapping), saving the language mapping through `POST /supertext/settings/languages` (administrator; invalid codes and the editor refused), the public ping (200 with the challenge, 400 otherwise), an export → import round trip of the article through the Studio API, and an `installed` report after a Studio request as administrator, sent to the stand-in's `POST /report` (`SUPERTEXT_REPORT_URL`), with the state stored in `settings_store` and no key in it.

CI (`.github/workflows/ci.yml`) on every push and pull request: **test** (PHP lint, PHPUnit; PHP 8.4 and 8.5), **phpstan** (see *Code quality and security checks*), **studio** (type check and build of the plugin) and **demo** (builds `demo/Dockerfile`, runs `tests/demo-check.sh`). The demo job needs the repository secrets `PIMCORE_ENCRYPTION_SECRET`, `PIMCORE_INSTANCE_IDENTIFIER` and `PIMCORE_PRODUCT_KEY`, and skips itself without them (e.g. for pull requests from forks).
`.github/workflows/checks.yml` and `links.yml` lint the workflows and check the links. `.github/workflows/e2e.yml` runs the end-to-end test against the real Supertext API and regenerates the docs screenshots (see *End-to-end tests*).

## End-to-end tests

The unit tests and the demo check use fakes and the stand-in. `.github/workflows/e2e.yml` also tests against the **real Supertext API**, so changes on Supertext's side (language codes, regional variants, formatting, rate limits) show up. It runs on every push to `main` that touches the bundle, the Studio plugin, the demo or the tests, on pull requests that change the E2E test or workflow, every Monday (05:45 UTC), and by hand (*Actions → E2E → Run workflow*). It needs the Pimcore product key secrets (see [Pimcore product key](#pimcore-product-key)) and without them runs only the language list (`features.mjs`, below), with a warning. It builds the demo image once (Docker layer cache in GitHub Actions) and starts it twice on MySQL and OpenSearch services, each with its own database (`PIMCORE_DB_NAME`) and demo accounts made up for the run:

1. **Real API** (repository or organization secret `SUPERTEXT_API_KEY`, set by Remy; without it these steps are skipped with a warning). Version reports are off (`SUPERTEXT_VERSION_REPORTS=0`), so CI runs don't appear in the Commando Center.
   - `tests/e2e/features.mjs` asks the live `GET features` (cost-free, no plugin or SDK code) whether every pair the demo translates (`en` → `de-CH`, `fr-CH`, `it-CH`) is offered; a missing pair fails the run. Whether the plugin's own language list matches the API is the SDK's job (its own tests).
   - `tests/e2e/live.mjs` (Playwright) logs in to Pimcore Studio as the demo editor (`DEMO_EDITOR_*`) and translates into every target language: the home page `/en` and the article `/Articles/swiss-chocolate` through the Supertext dialog in their editors, the pages below `/en` through the same Studio API route the dialog calls (`POST /pimcore-studio/api/supertext/elements/document/{id}/translate`, in the editor's session; opening a nested page needs fixed-position clicks in Studio's tree). The dialog must show a success for every language and no error. `tests/e2e/export.php`, copied into the demo container and run there (`docker exec`), prints the content at its latest versions (what Studio shows; the bundle saves existing translations and data objects as new versions) as JSON. Checked: every English page has a linked translation in each language with the right `language` property, new pages are unpublished, the article's published data is unchanged and it stays published, every text (title, description, navigation name, editables, link text, the article's fields) is filled and at least 80 % of them changed (a name like "Bern" can stay the same; unchanged ones are printed as `note`), no `data-st-id`/placeholder is left, links (and their targets), bold, list items and paragraphs are kept, the text is in the target language (common words of each language counted) and de-CH has no "ß". Then everything is translated again with *Overwrite existing translations*: the same documents and the same object get a new version, no documents or objects are added, and there is one Supertext note per element and language and run. Each run translates the demo content twice into three languages (around 4,000 characters on the key's account).
2. **Stand-in API**: the live demo is stopped and the OpenSearch indices dropped first (both demos use Mercure's port 3000 and the same index names), then the docs screenshots are taken (see *Docs screenshots*), so the images don't change with each machine translation. If `docs/images/` changed, a second job pushes the branch `automation/docs-screenshots` and opens (or updates) the pull request "Docs: regenerate screenshots". This needs *Settings → Actions → General → Allow GitHub Actions to create and approve pull requests*.

If a run fails, the artifact `e2e-debug` has the test's screenshots (dialog before and after each step, errors), both demos' container logs, `var/log`, the install, worker and search index logs, and the stand-in log. Locally:

```bash
cd tests/e2e && npm install && npx playwright install chromium
# fresh demo container named demo-live (new database) with a real SUPERTEXT_API_KEY, e.g. on port 8081
SUPERTEXT_API_KEY=… E2E_TARGETS=de-CH,fr-CH,it-CH node features.mjs
STUDIO_URL=http://127.0.0.1:8081 DEMO_CONTAINER=demo-live EDITOR_EMAIL=… EDITOR_PASSWORD=… npm run live
```

`live.mjs` also runs against the stand-in API, which returns real translations for the demo content, to try changes to it without a key.

## Demo (Railway)

The public demo is a container built from `demo/Dockerfile`: PHP 8.4 with Apache, Pimcore 2026 with Pimcore Studio and this bundle, the languages English (`en`, default), German, French and Italian (Switzerland: `de_CH`, `fr_CH`, `it_CH`, formal), two sample pages and a sample article object. It runs on Railway in the `supertext-cms-demos` project, service `Pimcore`, region EU West (Amsterdam): <https://pimcore-production-56d2.up.railway.app/> (Studio: `/pimcore-studio/`). Data lives in a `pimcore` database on the project's MySQL service; the search index in the service `Pimcore-OpenSearch` (OpenSearch 2, security plugin off, private network only).

**Deploys:** Railway builds `main` of this repository (`railway.json` points it at `demo/Dockerfile`).

**What's in `demo/`:**

| Path | Purpose |
| --- | --- |
| `Dockerfile` | PHP 8.4 + Apache (document root `demo/project/public`, `/.well-known/mercure` proxied to the Mercure hub), PHP extensions, the Mercure hub binary, the bundle copied to `demo/module`, Composer install of `demo/project` |
| `docker/entrypoint.sh` | Every start, see below |
| `docker/apache.conf` | The virtual host |
| `router.php` | Router for PHP's built-in server (local development) |
| `stage-module.sh` | Copies the bundle's files to `demo/module` for local installs |
| `project/` | The Pimcore project (from Pimcore's skeleton): `composer.json`, `config/bundles.php`, `config/packages/supertext_demo.yaml` (languages, read from this file), `config/pimcore/classes/` (the class `Article`), `templates/default/default.html.twig` and `src/Controller/DefaultController.php` (page layout with a language switcher), `src/Installer/SkeletonProfile.php` (install profile, Doctrine Messenger instead of RabbitMQ), `src/Command/DemoSetupCommand.php` |
| `.env.example` | The variables below |

**Every start** (`docker/entrypoint.sh`): creates the database `PIMCORE_DB_NAME` on the MySQL server if missing; on the first start installs Pimcore (`pimcore-install` with the skeleton profile; the installer's administrator is `DEMO_ADMIN_*` if set, otherwise an account `pimcore-install` with a random password nobody knows); then `cache:warmup` (which also unpacks Studio's frontend), Pimcore migrations, `pimcore:bundle:install SupertextTranslationBundle`, `pimcore:deployment:classes-rebuild --create-classes`, `assets:install`, a rebuild of the search index (OpenSearch keeps no data between deploys) and `supertext:demo-setup`. It then starts the Mercure hub, a Messenger worker (search index updates, maintenance; restarted hourly), `pimcore:maintenance` every 15 minutes, and Apache on `$PORT`.

**Demo setup** (`bin/console supertext:demo-setup`, only adds what is missing): the role **Editors** (permissions *Documents*, *Objects*, *Classes*, *Notes & Events* and *Translate with Supertext*; document and data object workspaces on the whole tree with all rights except delete, no language restrictions, so every language), the demo accounts, the English pages `/en` and `/en/swiss-chocolate`, the article `/Articles/swiss-chocolate`, and the language mapping on *Supertext → Settings* (`en` → `en-GB`, the Swiss languages as they are, formal), saved only while the screen has nothing saved, so changes made there are kept. An existing *Editors* role gets missing permissions added.

**No volume:** everything is in MySQL; OpenSearch is rebuilt at start. Uploaded assets would disappear with the next deploy.

**Service variables** (`Pimcore`):

| Variable | |
| --- | --- |
| `MYSQL_URL` | `${{MySQL.MYSQL_URL}}`; the demo uses the database `PIMCORE_DB_NAME` (default `pimcore`) on that server. `DATABASE_URL` works too. The entrypoint keeps the resulting `DATABASE_URL` in the container's `.env.local`, so `docker exec … bin/console` (and Railway's shell) reach the same database. |
| `PIMCORE_DB_SERVER_VERSION` | `8.0.0` for MySQL, e.g. `mariadb-10.11.14` for MariaDB |
| `PIMCORE_OPENSEARCH_DSN` | `opensearch://pimcore-opensearch.railway.internal:9200?ssl=false` |
| `PIMCORE_ENCRYPTION_SECRET`, `PIMCORE_INSTANCE_IDENTIFIER`, `PIMCORE_PRODUCT_KEY` | The registered instance (see [Pimcore product key](#pimcore-product-key)) |
| `APPLICATION_SECRET`, `MERCURE_JWT_KEY` | Long random strings; keep them stable (sessions, live updates) |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | Administrator |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Editor for automated tests and screenshots: role **Editors**, every language. Pimcore has no editor role out of the box, so the demo creates this one. |
| `SUPERTEXT_API_KEY` | Supertext key |
| `SUPERTEXT_API_URL` | Optional, e.g. a stand-in API |
| `SUPERTEXT_REVIEW_URL` | Optional: another review service than Supertext's for languages with *Translation review* (`review_url`; empty keeps the default). The demo starts with review off for every language. |
| `SUPERTEXT_VERSION_REPORTS` | Optional; `0` turns version reports to the Commando Center off (the demo reports like any installation by default) |
| `SUPERTEXT_REPORT_URL` | Tests only: where version reports go (the demo check uses the stand-in's `POST /report`) |
| `PUBLIC_URL` | Optional; otherwise `https://$RAILWAY_PUBLIC_DOMAIN` (Mercure's public address) |
| `PORT` | Port Apache listens on (`8080`) |

**Demo accounts:** on every start the setup creates the `DEMO_ADMIN` and `DEMO_EDITOR` accounts (user name = e-mail address) if no user with that name exists. Existing accounts are never changed; change passwords in Studio. Pimcore's rule is at least 4 characters for user name and password; an account that doesn't meet it is skipped with a warning naming the variables, and the demo still starts. Passwords are never logged. Pimcore 2026 has no web installer and no first-run screen; with `DEMO_ADMIN_*` set before the first start, that account is the installer's administrator.

**Run it locally:**

```bash
docker build -f demo/Dockerfile -t supertext-pimcore-demo .
docker run --rm --network host -e PORT=8080 \
  -e MYSQL_URL=mysql://root:…@127.0.0.1:3306/mysql -e PIMCORE_OPENSEARCH_DSN='opensearch://127.0.0.1:9200?ssl=false' \
  --env-file my-instance.env \   # PIMCORE_ENCRYPTION_SECRET, PIMCORE_INSTANCE_IDENTIFIER, PIMCORE_PRODUCT_KEY, APPLICATION_SECRET, MERCURE_JWT_KEY, DEMO_*, SUPERTEXT_API_KEY
  supertext-pimcore-demo
# http://localhost:8080/pimcore-studio/
```

## Docs screenshots

The images in `docs/images/` are generated by `tests/docs/screenshots.mjs` (Playwright) from a freshly set-up demo (no translations yet) whose bundle talks to `tests/docs/stand-in.mjs`. The stand-in returns German, French and Italian for the demo's content (`samples.json`, real Supertext output). The screenshots show no addresses. Regenerate them whenever a screen they show changes:

```bash
cd tests/docs && npm install && npx playwright install chromium
npm run stand-in &
# a fresh demo (DEMO_* set), served on :8080 with SUPERTEXT_API_KEY=anything SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/
#   SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report (version reports go to the stand-in, never to the live center)
BASE_URL=http://127.0.0.1:8080 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… \
  DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… npm run screenshots
```

`settings.png` (*Supertext → Settings*, as administrator) shows Supertext's live API address instead of the stand-in's (the script rewrites that one value on the screen).

The script uses a 1400×900 window; some clicks (the data object tree, the object's language switcher, the main menu) are at fixed positions in Studio's layout.
The dialog's "Translated with Supertext on …" dates are shown as 1 October 2026 (the script rewrites them in the dialog's API answers), so the images only change when the UI does. In CI the E2E workflow takes them (see *End-to-end tests*). The script uses a 1400×900 window; some clicks (the data object tree, the object's language switcher, the main menu) are at fixed positions in Studio's layout.

## Demo movie

`docs/images/demo.gif` (shown at the top of the README) is recorded by `tests/docs/movie.mjs` with Supertext's movie kit (`tests/docs/movie/`: Playwright screencast → ffmpeg; intro and outro cards, captions, cursor; Inter font under the SIL Open Font License in `movie/assets/fonts/`). Like the screenshots, it needs a **fresh** demo (sample content not yet translated) pointed at the stand-in API, `ffmpeg` and, for the voice, Piper:

```bash
pip install piper-tts                       # the voice; without it (or with MOVIE_VOICE=0) the movie is silent
cd tests/docs && npm install
npm run stand-in &                          # http://127.0.0.1:8765/v1/
# a fresh demo (new database, DEMO_* set) with SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/,
#   SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report and SUPERTEXT_API_KEY=st-demo-0000-0000-0000, e.g. on :8080
BASE_URL=http://127.0.0.1:8080 DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… npm run movie
```

About 43 seconds, 1280×720, an English voice and English captions: the demo editor has the English home page open in Pimcore Studio (the document tree folded away, so the caption never covers the toolbar), clicks *Translate with Supertext*, keeps German and French, unticks Italian, clicks *Translate* (the waiting time is cut) and opens the new, unpublished German page with *Open*. The script writes the GIF (800 px, 12 fps, keep it under 3 MB) to `docs/images/demo.gif` and the MP4 to `tests/docs/movie/out/Pimcore-Supertext-Translation.mp4` (git-ignored; `MOVIE_OUT` changes the path). The MP4 is not committed; the GIF is silent. The voice model (63 MB, pinned revision, checked by SHA-256) is downloaded once into `tests/docs/movie/voice-cache/` (git-ignored, never committed); the time spent generating speech is cut from the movie. Re-record it in the same commit when the toolbar button or the translate dialog change.

**Install and configuration movie** (`tests/docs/movie-install.mjs`, `npm run movie:install`): the same kit, voice and look, for administrators. Same setup as above (a fresh demo isn't needed), but signed in as the demo **administrator**:

```bash
BASE_URL=http://127.0.0.1:8080 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… npm run movie:install
```

About 90 seconds: a terminal (`kit.terminal()`) with the commands from `docs/INSTALLATION.md` (`composer config repositories…`, `composer require`, the line in `config/bundles.php`, `pimcore:bundle:install`, `assets:install`, `cache:clear`, the key as environment variable in `.env.local` with the fake key `st-demo-0000-0000-0000`, `supertext:check` with *the connection to Supertext works*; output shortened), where the key comes from (supertext.com → Integrations → API; create an account at supertext.com), then Pimcore Studio: the languages under *System → System Settings → Localization*, *Supertext → Settings* (the API key *Set* from the environment variable, Supertext's live API address shown instead of the stand-in's, German (Switzerland) → *German (Switzerland) (de-CH)*, *Translation review* left off, *Save*), and where editors find *Translate with Supertext*. No GIF; the MP4 goes to `tests/docs/movie/out/Pimcore-Supertext-Translation-install.mp4` (git-ignored, not committed; `MOVIE_OUT` changes the path). Re-record it when the installation steps or *Supertext → Settings* change.

Both scripts find Studio's main menu and the document tree's fold button by their elements, not by position; the window is 1280×720.

Voice: Piper voice “alba” (en_GB), trained on a dataset from the University of Edinburgh's Centre for Speech Technology Research, CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/)

## Code quality and security checks

- **Checks** (`.github/workflows/checks.yml`): actionlint and zizmor lint the workflows on every push and pull request; dependency review fails a pull request that adds a package with a known vulnerability (moderate or worse). Third-party actions are pinned to commit SHAs (Dependabot keeps them current). Run the linters locally with `pip install actionlint-py zizmor`, then `actionlint` and `zizmor .github/workflows`.
- **Links** (`.github/workflows/links.yml`): lychee checks the links in all Markdown files weekly and whenever docs change on `main`. Broken links open (or update) the issue "Broken links in the docs". Links that can't work from CI (local addresses, pages behind a login, placeholders) are excluded in `.lycheeignore`.
- **PHPStan** (job `phpstan` in `ci.yml`, configuration in `phpstan.neon`): level 5 on `src/`. PHPStan needs Pimcore's classes, so the job runs `composer install --no-dev --no-scripts` in the repository root first. Locally, after `composer install`: `phpstan analyse` (PHPStan 2.x, e.g. the `phpstan.phar` from its GitHub releases). Existing findings that aren't fixed yet are listed in `phpstan-baseline.neon` (regenerate with `phpstan analyse --generate-baseline` after fixing some); new code must not add any. The Studio plugin (TypeScript) is covered by `npm run check-types` and CodeQL.
- **GitHub settings** (set by Remy's setup script, not in the repo): secret scanning with push protection (a push containing a known token format is rejected; findings under *Security → Secret scanning*) and CodeQL default setup (findings under *Security → Code scanning* and as pull request comments). CodeQL doesn't cover PHP, which is why this repo runs PHPStan.
- `.github/workflows/support-triage.yml`: support requests from the Supertext integrations website arrive as issues labelled `support` (contact details stay in the Supertext CMS). Claude classifies each one: answers questions (marked `<!-- supertext:reply -->`, emailed to the sender by the CMS) and closes them, fixes bugs as pull requests for review (never merged automatically), labels feature requests `feature-request`. Only people with triage rights can add labels, so outsiders can't start it. Needs the organisation secret `ANTHROPIC_API_KEY`; the workflow is the same in every Supertext repo.

Before starting work in this repo, look at its open findings: code scanning alerts, secret scanning alerts, Dependabot pull requests and the issue "Broken links in the docs".

## Dependency updates

Dependabot (`.github/dependabot.yml`) opens weekly pull requests: minor and patch updates grouped into one, GitHub Actions in another, each major update on its own.

`.github/workflows/dependabot-merge.yml` merges a minor or patch package update by itself once every other check on its pull request has passed (it waits up to 85 minutes and leaves the pull request alone if a check fails). GitHub Actions updates and majors stay for a person: the workflow's built-in token can't merge changes to workflow files, and a major needs a decision. Merges by the workflow don't start CI on `main`; the weekly health check looks at `main`.

The Studio plugin's build tooling (`assets/`) follows what Pimcore Studio itself uses: Module Federation 2 (`@module-federation/rsbuild-plugin` 2, like Studio's `@module-federation/enhanced` 2), Rsbuild 1 and React 18.3. `assets/package.json` overrides `adm-zip` (^0.6.1) and `uuid` (^11.1.1), which `@pimcore/studio-ui-bundle` 2026.3 pulls in at vulnerable versions; they are only used by the build (`npm audit` is clean). Major updates the plugin can't take are ignored in `.github/dependabot.yml`:

- `react`, `react-dom`, `@types/react`, `@types/react-dom` >=19: Pimcore Studio 2026.3 shares React 18.3 with the plugin through Module Federation (`@pimcore/studio-ui-bundle` depends on `react` 18.3.x), so the plugin must build against the host's React.
- `@rsbuild/core`, `@rsbuild/plugin-react` >=2: Studio 2026.3's build plugins (`pluginGenerateEntrypoints`, `pluginWriteBuildId`) are made for Rsbuild 1 (Studio builds with Rsbuild 1 too).

Remove a rule when a new Studio version moves to React 19 or Rsbuild 2 (check `npm view @pimcore/studio-ui-bundle dependencies devDependencies`); remove the overrides when Studio requires fixed versions.

## Releasing

Releases are published by `.github/workflows/release.yml` when the version is officially bumped; nobody tags or creates releases by hand.

1. If the Studio plugin changed, rebuild it (`cd assets && npm run build`) and commit `public/build/` (the old build folder is replaced). If strings changed, update all four `translations/studio.*.yaml` (en, de, fr, it).
2. Move the *Unreleased* entries in `CHANGELOG.md` under a new `## X.Y.Z — YYYY-MM-DD` section, and keep an empty *Unreleased* above it.
3. There is no version field to change: Composer takes the version from the Git tag the workflow creates, and `supertext:check` prints it (`Settings::version()`, via `Composer\InstalledVersions`).
4. Push to `main`. The workflow tags `vX.Y.Z` and creates the GitHub release with the CHANGELOG section as notes (0.x versions as pre-releases). A push that adds no new version does nothing, and a version that is already released is skipped. After fixing a failed run, start it again with *Run workflow* on the *Release* workflow.

Submitting the package to Packagist is planned.

## Conventions

- PSR-12, PHP 8.4 (and 8.5), typed properties; keep `src/Api/` free of Pimcore and Symfony classes.
- Studio strings in `translations/studio.{en,de,fr,it}.yaml`: every new string in all four (formal address: Sie, vous, Lei; Pimcore's own terms; never translate "Supertext", `{{placeholders}}` or URLs). Console messages, logs, notes and version comments stay English.
- Messages from the API: the JSON carries the English `error`/`message` plus a `key` (`supertext.error.<key>`), `params` and Supertext's untranslated `detail`; `SupertextException` takes them as named arguments (`key: 'parent-missing'`), and the per-language results carry them too. `translate-modal.tsx` → `describe()` shows the translation (with the detail in brackets, and `supertext.error.key-help` after key errors) or the English text if the key is unknown. A new message needs its key in all four files.
- Keep the three docs in `docs/` current with every change (see `CLAUDE.md`).

## Known limitations / roadmap

- Pimcore Studio only; the classic admin interface is not supported.
- The Studio settings screen (*Supertext → Settings*) holds the language mapping, tone and *Translation review* only; the API key, address, review service address, timeout and version reports stay environment variables and YAML. Administrators see the key check at the bottom of the translate dialog, `supertext:check` prints it.
- No translation memory of its own. With *Translation review* on for a language, the review service keeps every translation and serves reviewers' corrections; they reach Pimcore only with the next translation (no push).
- Translation runs inside the editor's request (one language after the other, up to `timeout` each). Planned: a queued job (Generic Execution Engine) and a batch action for several elements.
- Data objects: localized fields at the top level only; field collections, object bricks, blocks and classification stores are not translated yet. Asset metadata and shared translations (*Translations*) are not covered.
- Documents: renderlet, snippet and relation editables are copied, not translated.
- Pimcore Studio 2026.3 only opens data objects for users with the *Classes* permission.
- Export for translation: one element per file (no export of several elements into one file yet); no console commands for translation files.
- Not on Packagist yet.
- Human (professional) translation orders are not supported yet (the WordPress plugin has them).
