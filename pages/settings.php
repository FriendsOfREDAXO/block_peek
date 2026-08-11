<?php

use FriendsOfRedaxo\BlockPeek\TemplateInstaller;

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

$content = '';
$content .= $templateMessage;
$content .= $templateForm;
$content .= $form->getMessage();
$content .= $form->get();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('block_peek_settings'), false);
$fragment->setVar('body', $content, false);

echo $fragment->parse('core/page/section.php');
