<?php

use FriendsOfRedaxo\BlockPeek\TemplateInstaller;
use FriendsOfRedaxo\BlockPeek\Picker\CliProvider;
use FriendsOfRedaxo\BlockPeek\Picker\PreviewStore;
use FriendsOfRedaxo\BlockPeek\Picker\PromptBuilder;

/** @var rex_addon $this */

if (!rex::getUser()->isAdmin()) {
    echo rex_view::error(rex_i18n::msg('block_peek_no_permission'));
    return;
}

// ----- Template fieldset (custom form, not rex_config_form — we write to rex_template).

$template = rex_template::forKey(TemplateInstaller::KEY);
// forKey() may return a stale handle pointing at a deleted row; verify existence.
if ($template === null || !rex_template::exists($template->getId())) {
    // forKey()'s mapping is statically cached; use the freshly-returned id directly.
    $template = new rex_template(TemplateInstaller::ensureExists());
}
$templateId = $template->getId();

$templateMessage = '';

if (rex_post('btn_save_template', 'string') !== ''
    && rex_csrf_token::factory('block_peek_template')->isValid()
) {
    $newContent = rex_post('block_peek_template_content', 'string', '');

    if (!str_contains($newContent, 'BLOCK_PEEK_CONTENT')) {
        // Without the placeholder every preview would render empty — reject the save.
        $templateMessage = rex_view::error(rex_i18n::msg('block_peek_template_missing_placeholder'));
    } else {
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('template'));
        $sql->setWhere('id = :id', ['id' => $templateId]);
        $sql->setValue('content', $newContent);
        $sql->addGlobalUpdateFields();
        $sql->update();

        rex_template_cache::delete($templateId);
        rex_template_cache::generate($templateId);
        rex_extension::registerPoint(new rex_extension_point('TEMPLATE_UPDATED', '', ['id' => $templateId]));

        $templateMessage = rex_view::success(rex_i18n::msg('block_peek_template_saved'));

        // Re-fetch by id (we just confirmed it exists above; safer than forKey here).
        $template = new rex_template($templateId);
    }
}

$currentContent = (string) $template->getTemplate();

ob_start();
?>
<form action="<?= rex_url::currentBackendPage() ?>" method="post">
    <?= rex_csrf_token::factory('block_peek_template')->getHiddenField() ?>
    <fieldset>
        <legend><?= rex_i18n::msg('block_peek_template') ?></legend>
        <div class="form-group">
            <p class="help-block"><?= rex_i18n::rawMsg('block_peek_template_notice') ?></p>
            <textarea
                name="block_peek_template_content"
                rows="20"
                class="form-control rex-code rex-html-code"
                autocapitalize="off"
                autocomplete="off"
                spellcheck="false"
            ><?= rex_escape($currentContent) ?></textarea>
        </div>
        <div class="form-group">
            <button type="submit" name="btn_save_template" value="1" class="btn btn-save">
                <?= rex_i18n::msg('block_peek_save') ?>
            </button>
        </div>
    </fieldset>
</form>
<?php
$templateForm = ob_get_clean();

// ----- Cache + Misc (still rex_config-backed).

$form = rex_config_form::factory('block_peek');

$form->addFieldset(rex_i18n::msg('block_peek_cache_fieldset'));

$field = $form->addSelectField('cache');
$field->setLabel(rex_i18n::msg('block_peek_cache'));
$select = $field->getSelect();
$select->addOption(rex_i18n::msg('block_peek_cache_auto'), 'auto');
$select->addOption(rex_i18n::msg('block_peek_cache_active'), 'active');
$select->addOption(rex_i18n::msg('block_peek_cache_inactive'), 'inactive');
$field->setNotice(html_entity_decode(rex_i18n::msg('block_peek_cache_notice')));
$field->setAttribute('class', 'form-control selectpicker');

$field = $form->addTextField('cache_ttl');
$field->setLabel(rex_i18n::msg('block_peek_cache_ttl'));
$field->setAttribute('type', 'number');
$field->setAttribute('min', '0');
$field->setAttribute('step', '100');
$field->setAttribute('placeholder', '3600');
$field->setAttribute('style', 'width: 100px;');
$field->setNotice(html_entity_decode(rex_i18n::msg('block_peek_cache_ttl_notice')));

$form->addFieldset(rex_i18n::msg('block_peek_misc'), ['style' => 'margin-top: 20px;']);

$field = $form->addCheckboxField('inactive');
$field->setLabel(rex_i18n::msg('block_peek_inactive'));
$field->addOption(rex_i18n::msg('block_peek_inactive_option'), 1);

$field = $form->addTextField('iframe_min_height');
$field->setLabel(rex_i18n::msg('block_peek_iframe_min_height'));
$field->setAttribute('type', 'number');
$field->setAttribute('min', '0');
$field->setAttribute('step', '50');
$field->setAttribute('placeholder', '300');
$field->setAttribute('style', 'width: 100px;');

$field = $form->addTextField('iframe_max_height');
$field->setLabel(rex_i18n::msg('block_peek_iframe_max_height'));
$field->setAttribute('type', 'number');
$field->setAttribute('min', '0');
$field->setAttribute('step', '100');
$field->setAttribute('placeholder', '10000');
$field->setAttribute('style', 'width: 100px;');

$field = $form->addTextField('iframe_zoom_factor');
$field->setLabel(rex_i18n::msg('block_peek_iframe_zoom_factor'));
$field->setAttribute('type', 'number');
$field->setAttribute('min', '0.1');
$field->setAttribute('max', '1.0');
$field->setAttribute('step', '0.05');
$field->setAttribute('placeholder', '0.5');
$field->setAttribute('style', 'width: 80px;');

$field = $form->addCheckboxField('force_fe');
$field->setLabel(rex_i18n::msg('block_peek_force_fe'));
$field->addOption(rex_i18n::msg('block_peek_force_fe_option'), 1);

$form->addFieldset(rex_i18n::msg('block_peek_picker_title'));

$field = $form->addCheckboxField('picker');
$field->setLabel(rex_i18n::msg('block_peek_picker_enabled'));
$field->addOption(rex_i18n::msg('block_peek_picker_yes'), 1);
$field->setNotice(rex_escape(rex_i18n::msg('block_peek_picker_enabled_notice')));

$field = $form->addSelectField('picker_provider');
$field->setLabel(rex_i18n::msg('block_peek_picker_provider'));
foreach (['auto', 'codex', 'claude', 'ai_platform', 'none'] as $provider) {
    $field->getSelect()->addOption(rex_i18n::msg('block_peek_picker_provider_' . $provider), $provider);
}

if (rex_addon::get('ai_platform')->isAvailable()) {
    $field = $form->addSelectField('picker_ai_profile');
    $field->setLabel(rex_i18n::msg('block_peek_picker_ai_profile'));
    $field->getSelect()->addOption(rex_i18n::msg('block_peek_picker_none'), '');
    try {
        foreach (\FriendsOfRedaxo\AiPlatform\Service::getInstance()->getProfiles() as $profile) {
            if (in_array($profile['type'], ['text', 'image_understanding'], true)) {
                $field->getSelect()->addOption($profile['name'], $profile['id']);
            }
        }
    } catch (Throwable $e) {
        rex_logger::logException($e);
    }
    $field->setNotice(rex_escape(rex_i18n::msg('block_peek_picker_ai_profile_notice')));
}

foreach (['cli_path', 'command', 'model', 'effort', 'language'] as $setting) {
    $field = $form->addTextField('picker_' . $setting);
    $field->setLabel(rex_i18n::msg('block_peek_picker_' . $setting));
    if ($setting === 'command') {
        $field->setNotice(rex_escape(rex_i18n::msg('block_peek_picker_command_notice')));
    } elseif ($setting === 'model') {
        $placeholder = match ($this->getConfig('picker_provider')) {
            'claude' => 'opus',
            'codex' => 'gpt-6.1-sol',
            'ai_platform' => '',
            default => 'gpt-6.1-sol / opus',
        };
        if ($placeholder !== '') {
            $field->setAttribute('placeholder', $placeholder);
        }
    } elseif ($setting === 'language') {
        $field->setAttribute('placeholder', 'de_de');
    } elseif ($setting === 'effort') {
        $field->setAttribute('placeholder', 'medium');
    }
}

$field = $form->addTextAreaField('picker_context_paths');
$field->setLabel(rex_i18n::msg('block_peek_picker_context_paths'));
$field->setAttribute('rows', '5');

$field = $form->addTextAreaField('picker_prompt');
$field->setLabel(rex_i18n::msg('block_peek_picker_prompt'));
$field->setAttribute('rows', '10');
$field->setNotice(rex_escape(rex_i18n::msg('block_peek_picker_prompt_notice')));
$form->addRawField('<details><summary>' . rex_escape(rex_i18n::msg('block_peek_picker_show_default')) . '</summary><pre>' . rex_escape(PromptBuilder::defaultPrompt()) . '</pre></details>');

$field = $form->addSelectField('picker_style_module');
$field->setLabel(rex_i18n::msg('block_peek_picker_style_module'));
$field->getSelect()->addOption(rex_i18n::msg('block_peek_picker_none'), '');
try {
    foreach ((new PreviewStore())->all() as $slug => $preview) {
        $field->getSelect()->addOption((string) ($preview['name'] ?? $slug), (string) $slug);
    }
} catch (Throwable $e) {
    rex_logger::logException($e);
    $form->addRawField(rex_view::warning(rex_escape(rex_i18n::msg('block_peek_picker_store_error'))));
}

foreach (['reference_image', 'php_path'] as $setting) {
    $field = $form->addTextField('picker_' . $setting);
    $field->setLabel(rex_i18n::msg('block_peek_picker_' . $setting));
}

$content = '';
$content .= $templateMessage;
$content .= $templateForm;
$content .= $form->getMessage();
$content .= $form->get();
$command = trim((string) $this->getConfig('picker_command', ''));
if ($command !== '' && ($errors = CliProvider::templateErrors($command)) !== []) {
    $content .= rex_view::warning(rex_escape(implode(' ', $errors)));
}

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('block_peek_settings'), false);
$fragment->setVar('body', $content, false);

echo $fragment->parse('core/page/section.php');
