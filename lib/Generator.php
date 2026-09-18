<?php

namespace FriendsOfRedaxo\BlockPeek;

use rex;
use rex_addon;
use rex_addon_interface;
use rex_article_content;
use rex_clang;
use rex_extension;
use rex_extension_point;
use rex_file;
use rex_sql;
use rex_template;

class Generator
{
    private rex_addon_interface $addon;
    private int $articleId = 0;
    private int $clangId = 0;
    private int $sliceId = 0;
    private int $updateDate = 0;
    private int $revision = 0;

    protected int $DEFAULT_TTL;
    public bool $cacheActive = true;

    public function __construct(int $articleId, int $clangId, int $sliceId, int $updateDate, int $revision)
    {
        $this->addon = rex_addon::get('block_peek');

        $this->articleId = $articleId;
        $this->clangId = $clangId;
        $this->sliceId = $sliceId;
        $this->updateDate = $updateDate;
        $this->revision = $revision;

        $cacheType = $this->addon->getConfig('cache', 'auto');
        $this->cacheActive = $cacheType === 'auto' && !rex::isDebugMode() ||
            $cacheType === 'active';

        // empty or 0 falls back to the default (use cache mode "inactive" to turn caching off)
        $this->DEFAULT_TTL = (int) $this->addon->getConfig('cache_ttl') ?: 3600;
    }

    public function getContent(): string
    {
        $template = $this->getTemplateRow();
        $templateUpdateDate = $this->fetchTemplateUpdateDate($template->getId());

        // One file per slice (slice ids are unique across clangs and revisions), so
        // a new version overwrites the old one and the cache dir stays bounded.
        $cacheFile = $this->addon->getCachePath("article-{$this->articleId}/slice-{$this->sliceId}.cache");

        if (!$this->cacheActive) {
            // Drop the entry so a visit in debug/inactive mode also refreshes what
            // gets served once caching is back on (the key doesn't cover media,
            // sprog wildcards, module code, …).
            rex_file::delete($cacheFile);
            return $this->prepareOutput($template->getId());
        }

        $cacheKey = md5($this->articleId . $this->sliceId . $this->updateDate . $this->revision . $templateUpdateDate);

        $cached = $this->readCache($cacheFile, $cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $content = $this->prepareOutput($template->getId());
        // serialize() instead of JSON: slice output isn't guaranteed to be valid
        // UTF-8, and json_encode() would fail on it — that slice would never cache.
        rex_file::put($cacheFile, serialize([
            'key' => $cacheKey,
            'expires' => time() + $this->DEFAULT_TTL,
            'content' => $content,
        ]));

        return $content;
    }

    /**
     * Returns the cached preview, or null on a miss (no file, unreadable, outdated
     * key or expired). Misses are simply overwritten by the next write.
     *
     * Plain rex_file instead of symfony/cache: the addon only needs a file cache
     * with a TTL, and a bundled symfony/cache pulls in psr/cache 2.x, which clashes
     * with the psr/cache 3.x bundled by rexstan (fatal error on content/edit as soon
     * as both addons are active).
     */
    private function readCache(string $cacheFile, string $cacheKey): ?string
    {
        $raw = rex_file::get($cacheFile);
        if ($raw === null) {
            return null;
        }
        $entry = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($entry) || ($entry['key'] ?? null) !== $cacheKey || ($entry['expires'] ?? 0) < time() || !is_string($entry['content'] ?? null)) {
            return null;
        }
        return $entry['content'];
    }

    private function getTemplateRow(): rex_template
    {
        $template = rex_template::forKey(TemplateInstaller::KEY);
        // forKey() can return a non-null handle pointing at a deleted row if the
        // key→id mapping cache is stale (e.g., row deleted via direct SQL without
        // clearing rex_template_cache). Verify the row actually exists.
        if ($template !== null && rex_template::exists($template->getId())) {
            return $template;
        }
        // Self-heal. Use the id returned by ensureExists() directly — forKey()'s
        // static mapping cache wouldn't see the row we just inserted within the
        // same request.
        $id = TemplateInstaller::ensureExists();
        return new rex_template($id);
    }

    private function fetchTemplateUpdateDate(int $templateId): int
    {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT updatedate FROM ' . rex::getTable('template') . ' WHERE id = ?', [$templateId]);
        if ($sql->getRows() !== 1) {
            return 0;
        }
        $value = $sql->getValue('updatedate');
        if (is_numeric($value)) {
            return (int) $value;
        }
        return (int) strtotime((string) $value);
    }

    private function prepareOutput(int $templateId): string
    {
        $forceFeContext = (bool) $this->addon->getConfig('force_fe', false);
        $wasBackend = rex::getProperty('redaxo');
        if ($forceFeContext) {
            rex::setProperty('redaxo', false);
        }

        try {
            $context = new rex_article_content($this->articleId, $this->clangId);
            $context->setSliceRevision($this->revision);
            $context->setTemplateId($templateId);

            $wrapperHtml = $context->getArticleTemplate();
            $sliceHtml = $context->getSlice($this->sliceId);

            $html = str_replace('BLOCK_PEEK_CONTENT', $sliceHtml, $wrapperHtml);

            $html = $this->injectPosterAndStyles($html);
            $html = $this->setHtmlLang($html);

            // Resolve sprog wildcards ({{ … }}) for the preview's language. sprog only
            // runs its OUTPUT_FILTER on the frontend (sprog/boot.php: if (!rex::isBackend())),
            // so without this the backend preview shows raw wildcards. Pass the preview's
            // clang explicitly — Wildcard::parse() defaults to rex_clang::getCurrentId(),
            // which in the backend is the admin's clang, not necessarily the one previewed.
            if (rex_addon::get('sprog')->isAvailable()) {
                $html = \Sprog\Wildcard::parse($html, $this->clangId);
            }

            return rex_extension::registerPoint(new rex_extension_point('BLOCK_PEEK_OUTPUT', $html, [
                'article_id' => $this->articleId,
                'clang' => $this->clangId,
                'slice_id' => $this->sliceId,
                'updateDate' => $this->updateDate,
                'revision' => $this->revision,
            ]));
        } finally {
            // Restore the backend context — leaving it flipped would make the rest
            // of the request (other slices, OUTPUT_FILTERs) think it's frontend.
            if ($forceFeContext) {
                rex::setProperty('redaxo', $wasBackend);
            }
        }
    }

    private function injectPosterAndStyles(string $html): string
    {
        $maxHeight = (int) $this->addon->getConfig('iframe_max_height') ?: 10000;
        $blockPeekPosterJs = (string) rex_file::get($this->addon->getAssetsPath('BlockPeekPoster.js'));
        $blockPeekPosterJs = str_replace('BLOCK_PEEK_PLACEHOLDER_MAX_HEIGHT', (string) $maxHeight, $blockPeekPosterJs);
        $blockPeekPosterJs = str_replace('BLOCK_PEEK_PLACEHOLDER_SLICE_ID', (string) $this->sliceId, $blockPeekPosterJs);
        $blockPeekPosterJs = '<script>' . $blockPeekPosterJs . '</script>';

        $blockPeekStyles = '<style>
        body { min-height: 0 !important; }
        </style>';

        $injected = preg_replace(
            '/<\/body>/i',
            $blockPeekStyles . $blockPeekPosterJs . '</body>',
            $html,
            1
        );
        return $injected ?? $html;
    }

    private function setHtmlLang(string $html): string
    {
        $clang = rex_clang::get($this->clangId);
        $langCode = $clang ? $clang->getCode() : 'en';
        return preg_replace('/<html(\s[^>]*)?>/i', '<html lang="' . htmlspecialchars($langCode, ENT_QUOTES) . '"$1>', $html, 1) ?? $html;
    }
}
