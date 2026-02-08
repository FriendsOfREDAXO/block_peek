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
    $updateDate = $slice->getValue('updatedate');
    $generator = new Generator(articleId: $sliceData['article_id'], clangId: $sliceData['clang'], sliceId: $sliceData['slice_id'], moduleId: $sliceData['module_id'], ctypeId: $sliceData['ctype'], updateDate: $updateDate, revision: $revision);
    $content = $generator->getContent();
    $html =
      '<div class="block-peek-wrapper" data-zoom-factor="' . $zoomFactor . '" style="--block-peek-min-height: ' . $minHeight . 'px;">
<iframe data-iframe-preview data-slice-id="' . $sliceData['slice_id'] . '" scrolling="no"
srcdoc="' . htmlspecialchars($content) . '" frameborder="0" class="block-peek-iframe" style="--block-peek-zoom-factor: ' . $zoomFactor . ';"></iframe>
</div>';
    $ep->setSubject($html);
  }
}
