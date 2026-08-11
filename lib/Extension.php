<?php

namespace FriendsOfRedaxo\BlockPeek;

use rex_addon;
use rex_article_slice;
use rex_extension_point;

class Extension
{
  public static function register(rex_extension_point $ep): void
  {
    /** @var rex_addon $addon */
    $addon = rex_addon::get('block_peek');
    $minHeight = (int) $addon->getConfig('iframe_min_height') ?: 300;
    $zoomFactor = (float) $addon->getConfig('iframe_zoom_factor') ?: 0.5;
    $sliceData = $ep->getParams();
    $revision = $sliceData['revision'] ?? 0;
    $slice = rex_article_slice::getArticleSliceById($sliceData['slice_id'], false, 0);
    if (!$slice) {
      $revision = 1;
      $slice = rex_article_slice::getArticleSliceById($sliceData['slice_id'], false, 1);
    }
    if (!$slice) {
      // Slice not found in either revision — keep REDAXO's default preview.
      return;
    }
    // updatedate is a datetime string — convert to a timestamp so the cache key
    // changes on every edit (a plain int cast would truncate it to the year).
    $updateDateValue = $slice->getValue('updatedate');
    $updateDate = is_numeric($updateDateValue) ? (int) $updateDateValue : (int) strtotime((string) $updateDateValue);
    $generator = new Generator(articleId: (int) $sliceData['article_id'], clangId: (int) $sliceData['clang'], sliceId: (int) $sliceData['slice_id'], updateDate: $updateDate, revision: (int) $revision);
    $content = $generator->getContent();
    $html =
      '<div class="block-peek-wrapper" data-zoom-factor="' . $zoomFactor . '" style="--block-peek-min-height: ' . $minHeight . 'px;">
<iframe inert data-iframe-preview data-slice-id="' . $sliceData['slice_id'] . '" scrolling="no"
srcdoc="' . htmlspecialchars($content) . '" frameborder="0" class="block-peek-iframe" style="--block-peek-zoom-factor: ' . $zoomFactor . ';"></iframe>
</div>';
    $ep->setSubject($html);
  }
}
