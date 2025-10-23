<?php

namespace FriendsOfRedaxo\BlockPeek;

use rex_addon;
use rex_article_slice;
use rex_extension_point;
use rex_url;

class Extension
{
  public static function register(rex_extension_point $ep): void
  {
    /** @var rex_addon $addon */
    $addon = rex_addon::get('block_peek');
    $minHeight = (int) $addon->getConfig('iframe_min_height') ?: 300;
    $zoomFactor = (float) $addon->getConfig('iframe_zoom_factor') ?: 0.5;
    $sliceData = $ep->getParams();
    $slice = rex_article_slice::getArticleSliceById($sliceData['slice_id']);
    $updateDate = $slice->getValue('updatedate');
    $endpoint = rex_url::backendController(array_merge(['rex-api-call' => 'block_peek_generate', 'updateDate' => $updateDate], $sliceData), false);
    $html =
      '<div class="block-peek-wrapper" data-zoom-factor="' . $zoomFactor . '" style="--block-peek-min-height: ' . $minHeight . 'px;">
<iframe data-iframe-preview data-slice-id="' . $sliceData['slice_id'] . '" scrolling="yes" loading="lazy"
src="' . $endpoint . '" frameborder="0" class="block-peek-iframe" style="--block-peek-zoom-factor: ' . $zoomFactor . ';" onload="this.style.visibility = \'visible\'; this.closest(\'.panel-body\').querySelector(\'.rex-ajax-loader\')?.remove()"></iframe>
</div>';

    $html .= '<div class="rex-visible rex-ajax-loader" style="position: absolute;">
<div class="rex-ajax-loader-element" style="width: 100px; height: 100px; margin: -50px 0 0 -50px;"></div>
</div>';
    $ep->setSubject($html);
  }
}
