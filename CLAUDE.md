# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`block_peek` is a REDAXO 5 backend addon (PHP 8.2+, REDAXO ^5.17). It replaces the default slice output in the article-edit view with a zoomed-down iframe rendering the slice as it would appear in the frontend. The README is in German.

## Common commands

```bash
# Build the JS/CSS bundle into ./assets/ (also syncs version + cleans assets/)
npm run build

# Watch mode (no sync)
npm run watch        # alias: npm run dev

# Sync version from package.yml -> package.json (also runs as part of prebuild)
npm run version:sync
```

PHP tests are assert scripts in `tests/`. Run from a REDAXO install with the addon (including `tests/`) and MASSIF available:

```bash
php -d zend.assertions=1 bin/console massif:exec-php "require 'src/addons/block_peek/tests/<Name>Test.php';"
```

Names: `SvgValidator`, `PreviewStore`, `Sources`, `PromptBuilder`, `Generator`, `AiPlatform`, `BootGate`. A pass produces only the console's OK line; warnings or assertion output are not a pass. `tests/` is excluded from release archives by the publish workflow. No test runner or PHP/JS linter is wired into npm scripts. `.stylelintrc.json` and `.prettierrc` exist — run `npx stylelint` / `npx prettier` directly if needed.

## Version is in package.yml, not package.json

`package.yml` is the single source of truth for the addon version. `vite.config.mjs` reads it directly, and `scripts/sync-version.js` (run via `prebuild`) copies it into `package.json`. When bumping the version, edit `package.yml` and let the build sync the rest. Also update `CHANGELOG.md`.

## Architecture

### Request flow (preview generation)

1. `boot.php` registers a late handler on REDAXO's `SLICE_BE_PREVIEW` extension point (only when `inactive` config is not `|1|`, the user is logged in, and we are in the backend).
2. `lib/Extension.php` (`FriendsOfRedaxo\BlockPeek\Extension::register`) is the EP callback. It reads slice params from the EP, instantiates a `Generator`, wraps the result in `<iframe srcdoc="...">`, and replaces the EP subject. **No HTTP round-trip** — the preview HTML is inlined as `srcdoc`, which is why loading is instant.
3. `lib/Generator.php` builds the inner HTML:
   - Cache key = `md5(articleId + sliceId + updateDate + revision + templateUpdateDate)`, stored with an `expires` timestamp in one serialized file per slice (`article-<id>/slice-<sliceId>.cache` in the addon's cache path), so new versions overwrite old ones. In inactive mode the slice's file is deleted. `update.php` wipes the cache path. No symfony/cache: its psr/cache 2.x clashes with the psr/cache 3.x bundled by rexstan. Cache mode (`auto` / `active` / `inactive`) lives in addon config; `auto` follows REDAXO debug mode.
   - Renders the slice via `rex_article_content::getSlice()` and the hidden template (`block_peek_internal`) via `getArticleTemplate()`, using REDAXO's normal template pipeline.
   - Substitutes `BLOCK_PEEK_CONTENT` with the slice HTML and sets `<html lang>` from the clang.
   - Inlines `assets/BlockPeekPoster.js` into `<script>` with two placeholders replaced: `BLOCK_PEEK_PLACEHOLDER_SLICE_ID`, `BLOCK_PEEK_PLACEHOLDER_MAX_HEIGHT`. This is what posts the iframe height back to the parent.
   - Resolves sprog wildcards in the preview's clang when sprog is available.
   - Fires the `BLOCK_PEEK_OUTPUT` extension point so projects can post-process.
   - Optional `force_fe` config flips `rex::setProperty('redaxo', false)` so frontend-only code paths run, restoring the previous value in `finally`.

### Module picker

`lib/Picker/` (`FriendsOfRedaxo\BlockPeek\Picker`) is separate from slice rendering. `PreviewStore` keeps `previews.json` and SVG files in the addon's `previews/` data directory, keyed by module key or ID, with input/output hashes and `sources` (sha1 per project-relative file the AI reported, filtered by `Sources`: inside the project, no dot paths, not cache/data/media/core, max 50). `PreviewStore::state()` is the single state rule (failed > missing > outdated by hash or changed source > current) used by the generator skip, admin page, popup and login notice. `SvgValidator` checks XML, the fixed viewBox and unsafe elements/attributes. `PromptBuilder` expands the six placeholders from `templates/picker-prompt.md` or a custom prompt. `Providers` selects a `Provider`: `CliProvider` detects and runs local Codex/Claude with shell-escaped arguments, stdin prompts and a 300-second timeout; `AiPlatformProvider` uses the configured profile, with `ContextInliner` embedding filtered project source files. Auto order is Codex → Claude → ai_platform. `PreviewGenerator` skips current previews, retries once, preserves previous SVGs on failure and respects description locks. `BackgroundRunner` launches the console using a detected/configured PHP CLI and reads progress from the addon cache. `PickerBoot` gates picker assets/data on `picker === '|1|'`, a backend user and `content/edit`, independently of iframe preview activation; `PickerBoot::staleStates()` feeds the admin-only nudge (popup line + dots, once-per-login notice via `PAGE_TITLE_SHOWN`), shown only when a provider is available.

`lib/Command/GenerateCommand.php` registers `block_peek:generate [--module=ID] [--force]` via `package.yml`; it checks the switch/provider and holds a non-blocking generation lock. Individual module failures appear in the summary but do not make the command exit nonzero. `pages/picker.php` is the admin UI for background generation, status and description editing; without a provider it is read-only.

### Frontend assets

Source in `assets-src/`, built into `assets/` (committed) by Vite. Vite externalizes `jquery` and `bootstrap` because REDAXO provides them.

- `BlockPeek.js` runs in the REDAXO backend page. Two responsibilities:
  - **Iframe sizing**: listens for `postMessage({type:"resize", id, height})` from each iframe and sets the wrapper's height to `height * zoomFactor`.
  - **Scroll restoration**: records edit/save/apply intent around native navigation/form submission, then restores the target slice after iframe heights settle, including bfcache restores.
- `BlockPeekPoster.js` runs **inside** the iframe (inlined by `Generator::injectPosterAndStyles`). It uses a `ResizeObserver` on `document.body` to post heights up. It also stubs `history.replaceState` to swallow errors — `srcdoc` iframes can't use the History API, and frontend code that calls it would otherwise throw (CHANGELOG 1.1.0).
- `BlockPeekPicker.js` / `BlockPeekPicker.css` are separate Vite entries, built to the same names in `assets/`. The JS uses delegated capture listeners for module selection, search and keyboard navigation, with SVGs rendered as data-URL images. CSS uses `bp-picker` classes, two columns (one below 768px) and 184px thumbnails at 16:10.

### Pages

- `pages/index.php` — wrapper that calls `rex_be_controller::includeCurrentPageSubPath()`.
- `pages/settings.php` — admin-only custom template form writing to `rex_template`, plus `rex_config_form` for cache, iframe and picker settings (provider/profile, CLI command/path/model/effort, language, prompt/context, references and PHP CLI path).
- The "docs" subpage is just `README.md` rendered via `subPath` in `package.yml`.

### Autoloading

No Composer: the addon has no PHP dependencies, and REDAXO autoloads `lib/` itself.

## Release / packaging

`.github/workflows/publish-to-redaxo.yml` runs on GitHub Release publish: zips the repo (excluding `assets-src/`, `scripts/`, `node_modules`, dotfiles, `*.json` package files, `vite.config.mjs`, etc. — see the workflow for the full list), uploads the zip to the release, and pushes to MyREDAXO via `FriendsOfREDAXO/installer-action`. The `installer_ignore` list in `package.yml` mirrors most of these exclusions for the in-REDAXO installer path.

Build artifacts under `assets/` ARE committed and shipped — do not add them to `.gitignore`.
