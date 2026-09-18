<?php

use FriendsOfRedaxo\BlockPeek\TemplateListHider;

/** @var rex_addon_interface $addon */
$addon = $this;

if ($addon->getConfig('inactive') !== '|1|') {
    // Previews only exist on content/edit — don't load assets on every backend page.
    if (rex::isBackend() && rex::getUser() && rex_be_controller::getCurrentPage() === 'content/edit') {
        rex_view::addJsFile($this->getAssetsUrl('BlockPeek.js'));
        rex_view::addCssFile($this->getAssetsUrl('BlockPeek.css'));
        rex_extension::register('PACKAGES_INCLUDED', function () {
            rex_extension::register('SLICE_BE_PREVIEW', \FriendsOfRedaxo\BlockPeek\Extension::register(...), rex_extension::LATE);
        });
    }
}

// Remove preview cache files along with their slice/article — regardless of the
// `inactive` setting, so files don't linger while previews are switched off.
if (rex::isBackend()) {
    rex_extension::register('SLICE_DELETED', static function (rex_extension_point $ep) use ($addon) {
        rex_file::delete($addon->getCachePath("article-{$ep->getParam('article_id')}/slice-{$ep->getParam('slice_id')}.cache"));
    });
    rex_extension::register('ART_DELETED', static function (rex_extension_point $ep) use ($addon) {
        rex_dir::delete($addon->getCachePath("article-{$ep->getParam('id')}"));
    });
}

// Hide the internal block_peek template row from the backend templates list page.
if (rex::isBackend()) {
    rex_extension::register('OUTPUT_FILTER', [TemplateListHider::class, 'register'], rex_extension::LATE);
}
