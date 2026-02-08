<?php

/** @var rex_addon_interface $addon */
$addon = $this;

/**
 * Add preview iframe to slice preview view in backend.
 */
if ($addon->getConfig('inactive') !== '|1|') {

    if (rex::isBackend() && rex::getUser()) {
        rex_view::addJsFile($this->getAssetsUrl('BlockPeek.js'));
        rex_view::addCssFile($this->getAssetsUrl('BlockPeek.css'));
        rex_extension::register('PACKAGES_INCLUDED', function () {
            rex_extension::register('SLICE_BE_PREVIEW', \FriendsOfRedaxo\BlockPeek\Extension::register(...), rex_extension::LATE);
        });
    }
}
