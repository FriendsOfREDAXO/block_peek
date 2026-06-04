<?php

namespace FriendsOfRedaxo\BlockPeek;

use rex_addon;
use rex_article_slice;
use rex_extension_point;

class Extension
{
    /**
     * @param rex_extension_point<string> $ep
     */
  public static function register(rex_extension_point $ep): void
  {
      /** @var rex_addon $addon */
      $addon = rex_addon::get('block_peek');
      $minHeight = (int) $addon->getConfig('iframe_min_height') ?: 300;
      $zoomFactor = (float) $addon->getConfig('iframe_zoom_factor') ?: 0.5;

      $articleId = (int) $ep->getParam('article_id', 0);
      $clangId = (int) $ep->getParam('clang', 0);
      $sliceId = (int) $ep->getParam('slice_id', 0);
      $revision = (int) $ep->getParam('revision', 0);

      if ($articleId <= 0 || $clangId <= 0 || $sliceId <= 0) {
          return;
      }

      $slice = rex_article_slice::getArticleSliceById($sliceId, false, 0);
      if ($slice === null) {
          $revision = 1;
          $slice = rex_article_slice::getArticleSliceById($sliceId, false, 1);
      }

      $updateDateValue = $slice?->getValue('updatedate') ?? 0;
      $updateDate = is_numeric($updateDateValue)
          ? (int) $updateDateValue
          : (int) strtotime((string) $updateDateValue);

      $generator = new Generator(
          articleId: $articleId,
          clangId: $clangId,
          sliceId: $sliceId,
          updateDate: $updateDate,
          revision: $revision,
      );

      $content = $generator->getContent();
      $html =
          '<div class="block-peek-wrapper" data-zoom-factor="' . $zoomFactor . '" style="--block-peek-min-height: ' . $minHeight . 'px;">
<iframe data-iframe-preview data-slice-id="' . $sliceId . '" scrolling="no"
srcdoc="' . htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" frameborder="0" class="block-peek-iframe" style="--block-peek-zoom-factor: ' . $zoomFactor . ';"></iframe>
</div>';
      $ep->setSubject($html);
  }
}
