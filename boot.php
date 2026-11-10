<?php

use FriendsOfRedaxo\BlockPeek\TemplateListHider;
use FriendsOfRedaxo\BlockPeek\Picker\PickerBoot;
use FriendsOfRedaxo\BlockPeek\Picker\PreviewGenerator;
use FriendsOfRedaxo\BlockPeek\Picker\PreviewStore;

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

if (rex::isBackend() && PickerBoot::shouldLoad($addon->getConfig('picker'), (string) rex_be_controller::getCurrentPage(), (bool) rex::getUser())) {
    rex_view::addJsFile($addon->getAssetsUrl('BlockPeekPicker.js'));
    rex_view::addCssFile($addon->getAssetsUrl('BlockPeekPicker.css'));
    rex_view::setJsProperty('block_peek_picker_i18n', [
        'search_label' => rex_i18n::msg('block_peek_picker_search_label'),
        'search_placeholder' => rex_i18n::msg('block_peek_picker_search_placeholder'),
    ]);
    rex_extension::register('PACKAGES_INCLUDED', static function () {
        try {
            $store = new PreviewStore();
            rex_view::setJsProperty('block_peek_picker', $store->forJs(PreviewGenerator::modules()));
            $states = PickerBoot::staleStates();
            if ($states !== []) {
                rex_view::setJsProperty('block_peek_picker_stale', [
                    'states' => (object) $states,
                    'labels' => array_combine(['outdated', 'missing', 'failed'], array_map(static fn (string $state): string => rex_i18n::msg('block_peek_picker_' . $state), ['outdated', 'missing', 'failed'])),
                    'text' => rex_i18n::msg('block_peek_picker_stale_count', (string) count($states)),
                    'link' => rex_i18n::msg('block_peek_picker_regenerate'),
                    'url' => rex_url::backendPage('block_peek/picker', [], false),
                ]);
            }
        } catch (Throwable $e) {
            rex_logger::logException($e);
        }
    });
}

// Once per login session, tell admins which module previews need a (re)generation.
if (rex::isBackend() && $addon->getConfig('picker') === '|1|' && rex::getUser()?->isAdmin()) {
    rex_extension::register('PAGE_TITLE_SHOWN', static function (rex_extension_point $ep) {
        try {
            $login = rex::getProperty('login');
            if (!$login instanceof rex_backend_login) {
                return null;
            }
            $start = $login->getSessionVar(rex_login::SESSION_START_TIME);
            if (!PickerBoot::loginNoticeDue($start, $login->getSessionVar('block_peek_stale_notice', null))) {
                return null;
            }
            $login->setSessionVar('block_peek_stale_notice', $start); // also when nothing is stale: check once per login
            $states = PickerBoot::staleStates();
            if ($states === []) {
                return null;
            }
            return $ep->getSubject() . rex_view::info(
                rex_escape(rex_i18n::msg('block_peek_picker_stale_count', (string) count($states)))
                . ' <a href="' . rex_url::backendPage('block_peek/picker') . '">' . rex_escape(rex_i18n::msg('block_peek_picker_regenerate')) . ' →</a>',
            );
        } catch (Throwable $e) {
            rex_logger::logException($e);
            return null;
        }
    });
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
