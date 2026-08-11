<?php

namespace FriendsOfRedaxo\BlockPeek;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Psr\Cache\CacheItemPoolInterface;

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
    private CacheItemPoolInterface $cache;
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

        $this->DEFAULT_TTL = (int) $this->addon->getConfig('cache_ttl', 3600);
    }

    public function getContent(): string
    {
        $template = $this->getTemplateRow();
        $templateUpdateDate = $this->fetchTemplateUpdateDate($template->getId());

        $this->cache = new FilesystemAdapter("article-{$this->articleId}", $this->DEFAULT_TTL, $this->addon->getCachePath());
        $cacheKey = md5($this->articleId . $this->sliceId . $this->updateDate . $this->revision . $templateUpdateDate);
        $cachedItem = $this->cache->getItem($cacheKey);

        if (!$cachedItem->isHit() || !$this->cacheActive) {
            $content = $this->prepareOutput($template->getId());
            $cachedItem->set($content);
            $cachedItem->expiresAfter($this->DEFAULT_TTL);
            $this->cache->save($cachedItem);
        } else {
            $content = $cachedItem->get();
        }

        return $content;
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
