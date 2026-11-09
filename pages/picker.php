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

$msg = static fn (string $key): string => rex_escape(rex_i18n::msg('block_peek_picker_' . $key));
if ($this->getConfig('picker') !== '|1|') {
    echo rex_view::info($msg('disabled') . ' <a href="' . rex_escape(rex_url::backendPage('block_peek/settings')) . '">' . $msg('settings') . '</a>');
    return;
}

$store = new PreviewStore();
$csrf = rex_csrf_token::factory('block_peek_picker');
$started = false;
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
    $action = rex_post('picker_action', 'string', '');
    if ($action !== '') {
        if (!$csrf->isValid()) {
            echo rex_view::error($msg('csrf_error'));
        } elseif ($provider !== null) {
            if ($action === 'save') {
                $slug = rex_post('picker_slug', 'string', '');
                if (isset($modulesBySlug[$slug])) {
                    if ($store->get($slug) === null) {
                        $module = $modulesBySlug[$slug];
                        $store->put($slug, [
                            'id' => $module['id'], 'key' => $module['key'], 'name' => $module['name'],
                            'description' => '', 'locked' => false, 'hash' => '',
                            'model' => '', 'generated' => 0, 'status' => 'missing',
                        ], null);
                    }
                    $store->setDescription($slug, rex_post('picker_description', 'string', ''), rex_post('picker_locked', 'bool', false));
                    echo rex_view::success($msg('saved'));
                }
            } elseif (in_array($action, ['generate', 'force'], true)) {
                $started = BackgroundRunner::start(null, $action === 'force');
                echo $started ? rex_view::info($msg('started')) : rex_view::error($msg('start_error'));
            }
        }
    }
    $previews = $store->all();
} catch (Throwable $e) {
    rex_logger::logException($e);
    echo rex_view::error($msg('store_error'));
    return;
}

$status = BackgroundRunner::status();
if ($status['running'] ?? false) {
    echo rex_view::info($msg('progress') . ' ' . (int) ($status['done'] ?? 0) . '/' . (int) ($status['total'] ?? 0));
    echo '<pre>' . rex_escape(implode("\n", array_slice((array) ($status['log'] ?? []), -10))) . '</pre>';
}
if ($started || ($status['running'] ?? false)) {
    echo '<script>setTimeout(() => location.reload(), 5000);</script>';
}

if ($provider === null) {
    echo rex_view::info($msg('read_only'));
} else {
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
?>
<div class="table-responsive">
    <table class="table table-striped table-hover">
        <thead><tr>
            <th scope="col"><?= $msg('thumbnail') ?></th>
            <th scope="col"><?= $msg('name') ?></th>
            <th scope="col"><?= $msg('status') ?></th>
            <th scope="col"><?= $msg('description') ?></th>
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
            ?>
            <tr>
                <td><?php if ($svg !== null): ?><img src="<?= rex_escape('data:image/svg+xml;charset=utf-8,' . rawurlencode($svg)) ?>" alt="<?= rex_escape($module['name']) ?>" width="184" height="115" style="object-fit: contain;"><?php endif ?></td>
                <th scope="row"><?= rex_escape($module['name']) ?></th>
                <td><?= $msg($state) ?></td>
                <td>
                    <?php if ($provider !== null): ?>
                        <form action="<?= rex_escape(rex_url::currentBackendPage()) ?>" method="post">
                            <?= $csrf->getHiddenField() ?>
                            <input type="hidden" name="picker_slug" value="<?= rex_escape($slug) ?>">
                            <div class="form-group">
                                <label for="picker-description-<?= (int) $module['id'] ?>"><?= $msg('description') ?></label>
                                <input type="text" id="picker-description-<?= (int) $module['id'] ?>" name="picker_description" class="form-control" value="<?= rex_escape((string) ($preview['description'] ?? '')) ?>">
                            </div>
                            <label><input type="checkbox" name="picker_locked" value="1"<?= ($preview['locked'] ?? false) ? ' checked' : '' ?>> <?= $msg('locked') ?></label>
                            <button type="submit" name="picker_action" value="save" class="btn btn-save"><?= $msg('save') ?></button>
                        </form>
                    <?php else: ?>
                        <?= rex_escape((string) ($preview['description'] ?? '')) ?>
                    <?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
