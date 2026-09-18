# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`block_peek` is a REDAXO 5 backend addon (PHP 8.2+, REDAXO ^5.17). It replaces the default slice output in the article-edit view with a zoomed-down iframe rendering the slice as it would appear in the frontend. The README is in German.

## Common commands

```bash
# PHP deps (matches the GitHub release workflow)
composer install --no-dev --optimize-autoloader

# Build the JS/CSS bundle into ./assets/ (also syncs version + cleans assets/)
npm run build

# Watch mode (no sync)
npm run watch        # alias: npm run dev

# Watch + rsync built assets into REDAXO's public/assets/addons/block_peek/
# (path is hardcoded as ../../../public/assets/addons/block_peek/, so this only
# works when this repo is checked out at <redaxo>/src/addons/block_peek)
npm run dev:sync

# Sync version from package.yml -> package.json (also runs as part of prebuild)
npm run version:sync
```

There is no test suite, no PHP linter, and no JS linter wired up. `.stylelintrc.json` and `.prettierrc` exist but are not invoked by any npm script — run `npx stylelint` / `npx prettier` directly if needed.

## Version is in package.yml, not package.json

`package.yml` is the single source of truth for the addon version. `vite.config.js` reads it directly, and `scripts/sync-version.js` (run via `prebuild`) copies it into `package.json`. When bumping the version, edit `package.yml` and let the build sync the rest. Also update `CHANGELOG.md`.

## Architecture

### Request flow (preview generation)

1. `boot.php` registers a late handler on REDAXO's `SLICE_BE_PREVIEW` extension point (only when `inactive` config is not `|1|`, the user is logged in, and we are in the backend).
2. `lib/Extension.php` (`FriendsOfRedaxo\BlockPeek\Extension::register`) is the EP callback. It reads slice params from the EP, instantiates a `Generator`, wraps the result in `<iframe srcdoc="...">`, and replaces the EP subject. **No HTTP round-trip** — the preview HTML is inlined as `srcdoc`, which is why loading is instant.
3. `lib/Generator.php` builds the inner HTML:
   - Cache key = `md5(articleId + sliceId + updateDate + revision)`, stored as a JSON file per entry (`rex_file::putCache`, with an `expires` timestamp) under `article-<id>/` in the addon's cache path. No symfony/cache: its psr/cache 2.x clashes with the psr/cache 3.x bundled by rexstan. Cache mode (`auto` / `active` / `inactive`) lives in addon config; `auto` follows REDAXO debug mode.
   - Renders the slice via `rex_article_content::getSlice()`.
   - Wraps it in the user-configured template (settings page), substituting the `{{block_peek_content}}` placeholder.
   - Injects `assets_head` / `assets_body` snippets, sets `<html lang>` from the clang.
   - Inlines `assets/BlockPeekPoster.js` into `<script>` with two placeholders replaced: `BLOCK_PEEK_PLACEHOLDER_SLICE_ID`, `BLOCK_PEEK_PLACEHOLDER_MAX_HEIGHT`. This is what posts the iframe height back to the parent.
   - Replaces REDAXO vars (`REX_MODULE_ID`, `REX_MODULE_KEY`, `REX_SLICE_ID`, `REX_CTYPE_ID`, common vars, `redaxo://` links), then writes the template to a temp file in the cache dir and `include`s it bound to the `rex_article_content` so `$this` works inside template PHP.
   - Fires the `BLOCK_PEEK_OUTPUT` extension point so projects can post-process.
   - Optional `force_fe` config flips `rex::setProperty('redaxo', false)` so frontend-only code paths run.

### Frontend assets

Source in `assets-src/`, built into `assets/` (committed) by Vite. Vite externalizes `jquery` and `bootstrap` because REDAXO provides them.

- `BlockPeek.js` runs in the REDAXO backend page. Two responsibilities:
  - **Iframe sizing**: listens for `postMessage({type:"resize", id, height})` from each iframe and sets the wrapper's height to `height * zoomFactor`.
  - **Async slice edit/save** on `rex.page === "content/edit"`: intercepts edit/save/apply clicks, fetches via `XMLHttpRequest`-marked requests, and swaps the `.rex-slice` DOM in place — no full page reload. It also handles back/forward navigation via `popstate` and re-runs CKEditor's `updateSourceElement()` before serializing the form (this matters; see CHANGELOG 1.1.1).
- `BlockPeekPoster.js` runs **inside** the iframe (via the inlined `<script>` from `Generator::getTemplate`). It uses a `ResizeObserver` on `document.body` to post heights up. It also stubs `history.replaceState` to swallow errors — `srcdoc` iframes can't use the History API, and frontend code that calls it would otherwise throw (CHANGELOG 1.1.0).

### Pages

- `pages/index.php` — wrapper that calls `rex_be_controller::includeCurrentPageSubPath()`.
- `pages/settings.php` — admin-only config form built with `rex_config_form`. Fields: `template`, `assets_head`, `assets_body`, `cache`, `cache_ttl`, `inactive`, `iframe_min_height`, `iframe_max_height`, `iframe_zoom_factor`, `force_fe`.
- The "docs" subpage is just `README.md` rendered via `subPath` in `package.yml`.

### Autoloading

`composer.json` uses a classmap on `lib/`. After editing classes there, the GitHub release action runs `composer install --no-dev --optimize-autoloader` to regenerate it; locally you may need `composer dump-autoload` if you add new classes.

## Release / packaging

`.github/workflows/publish-to-redaxo.yml` runs on GitHub Release publish: installs prod composer deps, zips the repo (excluding `assets-src/`, `scripts/`, `node_modules`, dotfiles, `*.json` package files, `vite.config.js`, etc. — see the workflow for the full list), uploads the zip to the release, and pushes to MyREDAXO via `FriendsOfREDAXO/installer-action`. The `installer_ignore` list in `package.yml` mirrors most of these exclusions for the in-REDAXO installer path.

Build artifacts under `assets/` ARE committed and shipped — do not add them to `.gitignore`.
