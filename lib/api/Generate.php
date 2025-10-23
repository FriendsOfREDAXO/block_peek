<?php

namespace FriendsOfRedaxo\BlockPeek\Api;

use Exception;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Psr\Cache\CacheItemPoolInterface;

use rex;
use rex_addon;
use rex_addon_interface;
use rex_api_function;
use rex_api_exception;
use rex_api_result;
use rex_article_content;
use rex_clang;
use rex_extension;
use rex_extension_point;
use rex_file;
use rex_path;
use rex_response;
use rex_var;

class Generate extends rex_api_function
{

  private rex_addon_interface $addon;
  private CacheItemPoolInterface $cache;
  private int $articleId = 0;
  private int $clangId = 0;
  private int $sliceId = 0;
  private int $updateDate = 0;
  private int $revision = 0;

  protected int $DEFAULT_TTL;
  protected $published = false;

  public bool $cacheActive = true;

  /**
   * @return rex_api_result
   * @throws rex_api_exception
   * @throws Exception
   */
  public function execute(): rex_api_result
  {


    /** @var rex_addon_interface $addon */
    $this->addon = rex_addon::get('block_peek');

    $this->articleId = rex_get('article_id', 'int', 0);
    $this->clangId = rex_get('clang', 'int', 0);
    $this->sliceId = rex_get('slice_id', 'int', 0);
    $this->updateDate = rex_get('updateDate', 'int', 0);
    $this->revision = rex_get('revision', 'int', 0);

    $cacheType = $this->addon->getConfig('cache', 'auto');
    $this->cacheActive = $cacheType === 'auto' && !rex::isDebugMode() ||
      $cacheType === 'active';

    $this->DEFAULT_TTL = (int) $this->addon->getConfig('cache_ttl', 3600);

    $html = $this->getContent();

    return $this->sendResponse($html);
  }

  /**
   * Fetch the slice data from the database.
   * @param int $ttl Time to live for cache (in seconds)
   * 
   * @return string
   * @throws rex_api_exception
   * @throws Exception
   */
  public function getContent(): string
  {

    $this->cache = new FilesystemAdapter("article-{$this->articleId}", $this->DEFAULT_TTL, $this->addon->getCachePath());
    $cacheKey = md5($this->articleId . $this->sliceId . $this->updateDate . $this->revision);
    $cachedItem = $this->cache->getItem($cacheKey);

    if (!$cachedItem->isHit() || !$this->cacheActive) {
      $content = $this->prepareOutput();
      $cachedItem->set($content);
      $cachedItem->expiresAfter($this->DEFAULT_TTL);
      $this->cache->save($cachedItem);
    } else {
      $content = $cachedItem->get();
    }

    return $content;
  }

  /**
   * Prepare the output for the response.
   * adds a full html structure and JS/CSS assets
   * @param string $html
   * 
   * @return string
   */
  private function prepareOutput(): string
  {

    $context = new rex_article_content();
    $context->setArticleId($this->articleId);
    $context->setClang($this->clangId);
    $context->setSliceRevision($this->revision);
    $html = $context->getSlice($this->sliceId);

    $template = $this->getTemplate($context);

    $html = str_replace('{{block_peek_content}}', $html, $template);
    $html = rex_extension::registerPoint(new rex_extension_point('BLOCK_PEEK_OUTPUT', $html, [
      'article_id' => $this->articleId,
      'clang' => $this->clangId,
      'slice_id' => $this->sliceId,
      'updateDate' => $this->updateDate,
      'revision' => $this->revision,
    ]));

    return $html;
  }

  private function getTemplate(rex_article_content $context): string
  {
    $clang = rex_clang::get($this->clangId);
    $langCode = $clang ? $clang->getCode() : 'en';

    $template = $this->addon->getConfig('template', '{{block_peek_content}}');
    $template = rex_var::parse($template, rex_var::ENV_FRONTEND, 'template', $context);
    $maxHeight = (int) $this->addon->getConfig('iframe_max_height') ?: 10000;
    $headAssets = $this->addon->getConfig('assets_head', '');
    $bodyAssets = $this->addon->getConfig('assets_body', '');
    $template = str_replace('</head>', $headAssets . '</head>', $template);
    $template = str_replace('</body>', $bodyAssets . '</body>', $template);

    $template = str_replace('<html>', '<html lang="' . $langCode . '">', $template);
    $blockPeekPosterJs = rex_file::get($this->addon->getAssetsPath('BlockPeekPoster.js'));
    $blockPeekPosterJs = str_replace('BLOCK_PEEK_PLACEHOLDER_MAX_HEIGHT', $maxHeight, $blockPeekPosterJs);
    $blockPeekPosterJs = str_replace('BLOCK_PEEK_PLACEHOLDER_SLICE_ID', $this->sliceId, $blockPeekPosterJs);
    $blockPeekPosterJs = '<script>' . $blockPeekPosterJs . '</script>';

    $blockPeekStyles = '<style>
    html { scrollbar-gutter: unset !important;}
    body { min-height: 0 !important; pointer-events: none !important; }
    </style>';
    $template = str_replace('</body>', $blockPeekStyles . $blockPeekPosterJs . '</body>', $template);

    $template = $this->generateTemplate($template, $context);
    return $template;
  }

  private function generateTemplate(string $template, rex_article_content $context): string
  {
    $tempFile = rex_path::cache('blockpeek_' . uniqid() . '.php');
    rex_file::put($tempFile, $template);
    try {

      // Include and capture output
      ob_start();
      $executeInContext = function ($file) {
        include $file;
      };

      // Bind to article context so $this works
      $bound = $executeInContext->bindTo($context, $context);
      $bound($tempFile);

      $output = ob_get_clean();
      // Clean up
    } finally {
      @unlink($tempFile);
    }
    return $output;
  }

  /**
   * Sends the response with the given content and status.
   * @param string $content
   * @param string $status
   * 
   * @return rex_api_result
   */
  private function sendResponse(string $content, string $status = rex_response::HTTP_OK): rex_api_result
  {

    rex_response::setStatus($status);
    exit($content);
    return new rex_api_result(true);
  }
}
