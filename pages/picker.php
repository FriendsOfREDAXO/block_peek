<?php

use FriendsOfRedaxo\BlockPeek\Picker\BackgroundRunner;
use FriendsOfRedaxo\BlockPeek\Picker\PreviewGenerator;
use FriendsOfRedaxo\BlockPeek\Picker\PreviewStore;
use FriendsOfRedaxo\BlockPeek\Picker\Providers;

/** @var rex_addon $this */

if (!rex::getUser()->isAdmin()) {
    echo rex_view::error(rex_escape(rex_i18n::msg('block_peek_no_permission')));
    return;
}

$action = rex_post('picker_action', 'string', '');
$msg = static fn (string $key): string => rex_escape(rex_i18n::msg('block_peek_picker_' . $key));
if ($this->getConfig('picker') !== '|1|') {
    if ($action !== '') {
        rex_response::sendRedirect(rex_url::currentBackendPage(['picker_message' => 'disabled'], false));
    }
    echo rex_view::info($msg('disabled') . ' <a href="' . rex_escape(rex_url::backendPage('block_peek/settings')) . '">' . $msg('settings') . '</a>');
    return;
}

$store = new PreviewStore();
$csrf = rex_csrf_token::factory('block_peek_picker');
$message = '';
$status = BackgroundRunner::status();
try {
    $provider = Providers::fromConfig();
} catch (Throwable $e) {
    rex_logger::logException($e);
    echo rex_view::warning($msg('provider_error'));
    $provider = null;
}

try {
    $modules = PreviewGenerator::modules();
    $modulesBySlug = [];
    foreach ($modules as $module) {
        $modulesBySlug[PreviewStore::slug($module['id'], $module['key'])] = $module;
    }
    if ($action !== '') {
        $message = 'store_error';
        if (!$csrf->isValid()) {
            $message = 'csrf_error';
        } elseif ($status['running'] ?? false) {
            $message = 'running';
        } elseif ($provider !== null) {
            if ($action === 'save') {
                // Compare against the values the form was rendered with, not the store:
                // a stale tab must not revert descriptions regenerated since it was loaded.
                $stored = $store->all();
                $locked = rex_post('picker_locked', 'array', []);
                $original = rex_post('picker_original', 'array', []);
                $originalLocked = rex_post('picker_original_locked', 'array', []);
                $normalize = static fn (string $t): string => trim((string) preg_replace('/\s+/u', ' ', $t)); // descriptions are single-line
                foreach (rex_post('picker_description', 'array', []) as $slug => $text) {
                    $slug = (string) $slug; // numeric slugs arrive as int keys
                    if (!isset($modulesBySlug[$slug]) || !is_string($text)) {
                        continue;
                    }
                    $text = $normalize($text);
                    $isLocked = isset($locked[$slug]);
                    $textChanged = $text !== $normalize((string) ($original[$slug] ?? ''));
                    $lockChanged = $isLocked !== !empty($originalLocked[$slug]);
                    if ($textChanged || $lockChanged) {
                        $store->setDescription(
                            $slug,
                            $textChanged ? $text : (string) ($stored[$slug]['description'] ?? ''),
                            $lockChanged ? $isLocked : (bool) ($stored[$slug]['locked'] ?? false),
                            $modulesBySlug[$slug],
                        );
                    }
                }
                $message = 'saved';
            } elseif (in_array($action, ['generate', 'force'], true)) {
                $message = BackgroundRunner::start(null, $action === 'force') ? 'started' : 'start_error';
            }
        } else {
            $message = 'read_only';
        }
    }
    $previews = $store->all();
} catch (Throwable $e) {
    rex_logger::logException($e);
    if ($action === '') {
        echo rex_view::error($msg('store_error'));
        return;
    }
    $message = 'store_error';
}

if ($action !== '') {
    rex_response::sendRedirect(rex_url::currentBackendPage(['picker_message' => $message], false));
}
$message = rex_get('picker_message', 'string', '');
$messages = ['saved' => 'success', 'started' => 'info', 'csrf_error' => 'error', 'start_error' => 'error', 'store_error' => 'error', 'read_only' => 'info', 'running' => 'info'];
if (isset($messages[$message])) {
    echo rex_view::{$messages[$message]}($msg($message));
}

if ($status['running'] ?? false) {
    echo rex_view::info($msg('progress') . ' ' . (int) ($status['done'] ?? 0) . '/' . (int) ($status['total'] ?? 0));
    echo '<pre>' . rex_escape(implode("\n", array_slice((array) ($status['log'] ?? []), -10))) . '</pre>';
}
if ($message === 'started' || ($status['running'] ?? false)) {
    echo '<script>setTimeout(() => { try { sessionStorage.setItem("bp-picker-scroll", String(scrollY)); } catch {} location.replace(' . json_encode(rex_url::currentBackendPage([], false), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '); }, 5000);</script>';
}

if ($provider === null) {
    echo rex_view::info($msg('read_only'));
} elseif (!($status['running'] ?? false)) {
    ?>
    <form action="<?= rex_escape(rex_url::currentBackendPage()) ?>" method="post">
        <?= $csrf->getHiddenField() ?>
        <div class="form-group">
            <button type="submit" name="picker_action" value="generate" class="btn btn-primary"><?= $msg('generate') ?></button>
            <button type="submit" name="picker_action" value="force" class="btn btn-default"><?= $msg('generate_all') ?></button>
        </div>
    </form>
    <?php
}
$editable = $provider !== null && !($status['running'] ?? false);
$labels = ['current' => 'success', 'outdated' => 'warning', 'failed' => 'danger', 'missing' => 'default'];
ob_start();
?>
<style>
    .bp-picker-admin td, .bp-picker-admin th { vertical-align: middle !important; }
    .bp-picker-admin img { display: block; border-radius: 3px; background: #fff; object-fit: contain; }
    .bp-picker-admin textarea { field-sizing: content; min-height: 0; resize: vertical; }
    .bp-picker-admin .bp-lock-col { width: 1%; white-space: nowrap; text-align: center; }
    .bp-picker-admin .bp-lock-col label { margin: 0; font-weight: normal; cursor: pointer; }
    .bp-picker-admin thead .bp-lock-col input { margin: 0 4px 0 0; vertical-align: -2px; }
</style>
<div class="table-responsive bp-picker-admin">
    <table class="table table-striped table-hover">
        <thead><tr>
            <th scope="col"><?= $msg('thumbnail') ?></th>
            <th scope="col"><?= $msg('name') ?></th>
            <th scope="col"><?= $msg('description') ?></th>
            <?php if ($editable): ?>
                <th scope="col" class="bp-lock-col"><label><input type="checkbox" id="bp-lock-all"> <?= $msg('lock_all') ?></label></th>
            <?php endif ?>
        </tr></thead>
        <tbody>
        <?php foreach ($modules as $module):
            $slug = PreviewStore::slug($module['id'], $module['key']);
            $preview = $previews[$slug] ?? null;
            $svg = $store->svg($slug);
            $state = match (true) {
                ($preview['status'] ?? '') === 'failed' => 'failed',
                $preview === null || $svg === null => 'missing',
                ($preview['hash'] ?? '') !== PreviewStore::hash($module['input'], $module['output']) => 'outdated',
                default => 'current',
            };
            $id = 'bp-desc-' . rex_escape($slug);
            ?>
            <tr>
                <td><?php if ($svg !== null): ?><img src="<?= rex_escape('data:image/svg+xml;charset=utf-8,' . rawurlencode($svg)) ?>" alt="<?= rex_escape($module['name']) ?>" width="184" height="115"><?php endif ?></td>
                <th scope="row">
                    <?php if ($editable): ?><label for="<?= $id ?>"><?= rex_escape($module['name']) ?></label><?php else: ?><?= rex_escape($module['name']) ?><?php endif ?>
                    <br><span class="label label-<?= $labels[$state] ?>"><?= $msg($state) ?></span>
                </th>
                <td>
                    <?php if ($editable): ?>
                        <input type="hidden" name="picker_original[<?= rex_escape($slug) ?>]" value="<?= rex_escape((string) ($preview['description'] ?? '')) ?>">
                        <input type="hidden" name="picker_original_locked[<?= rex_escape($slug) ?>]" value="<?= ($preview['locked'] ?? false) ? '1' : '' ?>">
                        <textarea id="<?= $id ?>" name="picker_description[<?= rex_escape($slug) ?>]" class="form-control" rows="2"><?= rex_escape((string) ($preview['description'] ?? '')) ?></textarea>
                    <?php else: ?>
                        <?= rex_escape((string) ($preview['description'] ?? '')) ?>
                    <?php endif ?>
                </td>
                <?php if ($editable): ?>
                    <td class="bp-lock-col"><label><input type="checkbox" class="bp-lock" name="picker_locked[<?= rex_escape($slug) ?>]" value="1"<?= ($preview['locked'] ?? false) ? ' checked' : '' ?>><span class="sr-only"> <?= $msg('locked') ?>: <?= rex_escape($module['name']) ?></span></label></td>
                <?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php if ($editable): ?>
    <p class="help-block"><?= $msg('lock_notice') ?></p>
<?php endif ?>
<?php
$fragment = new rex_fragment();
$fragment->setVar('class', $editable ? 'edit' : 'default', false);
$fragment->setVar('title', $msg('title'), false);
$fragment->setVar('body', ob_get_clean(), false);
if ($editable) {
    $fragment->setVar('buttons', '<button type="submit" name="picker_action" value="save" class="btn btn-save">' . $msg('save') . '</button>', false);
    echo '<form action="' . rex_escape(rex_url::currentBackendPage()) . '" method="post">' . $csrf->getHiddenField() . $fragment->parse('core/page/section.php') . '</form>';
    ?>
    <script>
    (() => {
        const all = document.getElementById('bp-lock-all');
        const boxes = [...document.querySelectorAll('.bp-lock')];
        const sync = () => {
            const n = boxes.filter((b) => b.checked).length;
            all.checked = n > 0 && n === boxes.length;
            all.indeterminate = n > 0 && n < boxes.length;
        };
        all.addEventListener('change', () => boxes.forEach((b) => { b.checked = all.checked; }));
        boxes.forEach((b) => b.addEventListener('change', sync));
        sync();
    })();
    </script>
    <?php
} else {
    echo $fragment->parse('core/page/section.php');
}
?>
<script>
// The page reloads every 5 s while generating; keep the scroll position across reloads.
try {
    const y = sessionStorage.getItem('bp-picker-scroll');
    sessionStorage.removeItem('bp-picker-scroll');
    if (y !== null) scrollTo(0, Number(y));
} catch {}
</script>
