# Changelog

## **04.06.2026 Version 1.3.1**

- fix: mehrere RexStan-Befunde behoben (u. a. Null-Safety beim Slice-Rendering, typisierte Konstruktoren, generische Extension-Point-Annotationen)
- fix: Preview-Rendering stabilisiert durch defensivere Parameterbehandlung und saubere Rücksetzung des REDAXO-Kontexts bei `force_fe`
- fix: Settings-Formular abgesichert (Admin-Null-Check); das Speichern des Templates validiert nun den Pflichtplatzhalter `BLOCK_PEEK_CONTENT`
- ux: Assets werden nur noch auf `content/edit` geladen statt global im Backend
- chore: internen Dateizugriff auf die REDAXO-API (`rex_file::get`) vereinheitlicht

## **04.06.2026 Version 1.3.0**

- feat: resolve sprog wildcards (`{{ … }}`) in slice previews when the `sprog` addon is installed — parsed in the preview's clang before the iframe is built, so backend previews match the translated frontend output (sprog's own wildcard filter only runs on the frontend)

## **28.05.2026 Version 1.2.2**

- chore: bump deps

## **28.05.2026 Version 1.2.1**

- fix: mixed named parameters and positional parameters
- chore: fix formatting

## **08.05.2026 Version 1.2.0**

- refactor(js): replace async fetch-and-swap edit pipeline with native form submission + scroll restoration on page load
  - eliminates malformed JSON payloads caused by `FormData` multipart encoding differences vs. PHP's expected encoding
  - fixes a leaked `message` event listener that accumulated on every `rex:ready`
  - drops `popstate` handling, manual `history.pushState`, manual CKEditor `updateSourceElement` calls, and the `restoreExistingSlice` re-fetch path
  - apply detection corrected: REDAXO uses submitter `name="btn_update"` (was previously matched by a `/apply/i` regex that didn't fit)
  - on edit/save/apply, page reloads natively; scroll position is restored to the target slice once iframe heights have settled (500ms settle window, 500ms late-shift watch)
  - bfcache / non-reload navigation handled via `pageshow` listener; stale scroll-intent entries expire after 30s
  - chore: drop unused `dev:sync`, `build:sync`, `clean:assets` scripts and `chokidar-cli`, `concurrently`, `baseline-browser-mapping` deps
- feat: store preview template as a hidden `rex_template` row (key: `block_peek_internal`) instead of `rex_config['template']`
  - enables Tailwind 4 `@source` discovery via the `developer` addon's filesystem mirror
  - uses REDAXO's native rendering pipeline (drops `Generator::generateTemplate` + `Generator::replaceVars` machinery, ~60 lines lighter)
  - existing customized templates are auto-migrated on install — no user action required
- feat: drop `assets_head` / `assets_body` settings fields — their content auto-merges into the template at install time, then they're removed from the form (one place to edit)
- feat: settings page template editor saves directly to the `rex_template` row, fires `TEMPLATE_UPDATED` so listeners (e.g., `developer` addon) react
- feat: hide `block_peek_internal` template from the templates list page via backend `OUTPUT_FILTER`
- feat: placeholder renamed `{{block_peek_content}}` → `BLOCK_PEEK_CONTENT` (auto-rewritten during migration)
- fix: cache key now includes template `updatedate` so template edits invalidate stale entries
- fix: `rex_article_content` constructor now sets clang before article id (latent ordering bug in previous Generator)

## **14.03.2026 Version 1.1.2**

- fix: actually remove src files in build output

## **14.03.2026 Version 1.1.1**

- fix: ckeditor 5 not saving data
- fix: remove src files in build output

## **08.02.2026 Version 1.1.0**

- feat: use resize observer instead of mutation observer
- feat: wrap code in anon function call (should fix https://github.com/FriendsOfREDAXO/block_peek/issues/5)
- feat: mock history replace method (about:srcdoc iframes can't use the History API)
- feat: super fast and snappy async slice edit, save and upate handling
- feat: use srcDoc for almost instant loading, remove API
- feat: replace all vars for slice and template contexts
- perf: remove loader
- feat: update dependencies

## **27.10.2025 Version 1.0.3**

- Update README with image and description enhancements
- fix: instructions

## **23.10.2025 Version 1.0.3-beta**

- feat: add english strings
- fix: add option to force frontend context
- fix: handle slice revision
- fix: ignore own zip file

## **23.10.2025 Version 1.0.2-beta**

- fix: add missing dependencies

## **23.10.2025 Version 1.0.1-beta**

- fix: replace common vars
- fix: update details
- fix: add composer json
- fix: scripts
- fix: workflow and php version

## **23.10.2025 Version 1.0.0-beta**

- feat: 1.0.0-beta (initial commit)
