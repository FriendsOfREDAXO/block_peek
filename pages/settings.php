<?php

/** @var rex_addon $this */

if (!rex::getUser()->isAdmin()) {
  echo rex_view::error(rex_i18n::msg('block_peek_no_permission'));
  return;
}

$htmlTemplate = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body>
    {{block_peek_content}}
</body>
</html>';

$addon = rex_addon::get('block_peek');
$form = rex_config_form::factory('block_peek');

$form->addFieldset('Template');

$field = $form->addTextAreaField('template');
$field->setLabel(rex_i18n::msg('block_peek_template'));
$field->setAttribute('rows', 20);
$field->setAttribute('class', 'form-control rex-code rex-html-code');
$field->setAttribute('autocapitalize', 'off');
$field->setAttribute('autocomplete', 'off');
$field->setAttribute('spellcheck', 'false');
$field->setNotice(html_entity_decode(rex_i18n::msg('block_peek_template_notice')) . '<br><pre>' . htmlentities($htmlTemplate) . '</pre>');

$field = $form->addTextAreaField('assets_head');
$field->setLabel(rex_i18n::msg('block_peek_assets_head'));
$field->setAttribute('rows', 4);
$field->setAttribute('class', 'form-control rex-code rex-html-code');
$field->setAttribute('autocapitalize', 'off');
$field->setAttribute('autocomplete', 'off');
$field->setAttribute('spellcheck', 'false');
$field->setAttribute('placeholder', '<link rel="stylesheet" href="<?=rex_path::base()?>/theme/public/assets/custom.css" media="all">');
$field->setNotice(html_entity_decode(rex_i18n::msg('block_peek_assets_head_notice')));

$field = $form->addTextAreaField('assets_body');
$field->setLabel(rex_i18n::msg('block_peek_assets_body'));
$field->setAttribute('rows', 4);
$field->setAttribute('class', 'form-control rex-code rex-html-code');
$field->setAttribute('autocapitalize', 'off');
$field->setAttribute('autocomplete', 'off');
$field->setAttribute('spellcheck', 'false');
$field->setAttribute('placeholder', '<style>
p { color: pink !important; }
</style>');
$field->setNotice(html_entity_decode(rex_i18n::msg('block_peek_assets_body_notice')));

$form->addFieldset('Cache', ['style' => 'margin-top: 20px;']);

$field = $form->addSelectField('cache');
$field->setLabel(rex_i18n::msg('block_peek_cache'));
$select = $field->getSelect();
$select->addOption(rex_i18n::msg('block_peek_cache_auto'), 'auto');
$select->addOption(rex_i18n::msg('block_peek_cache_active'), 'active');
$select->addOption(rex_i18n::msg('block_peek_cache_inactive'), 'inactive');
$field->setNotice(html_entity_decode(rex_i18n::msg('block_peek_cache_notice')));
$field->setAttribute('class', 'form-control selectpicker');
// $field->setAttribute('style', 'max-width: max-content;');

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
$field->setAttribute('placeholder', '0,5');
$field->setAttribute('style', 'width: 80px;');

$field = $form->addCheckboxField('force_fe');
$field->setLabel(rex_i18n::msg('block_peek_force_fe'));
$field->addOption(rex_i18n::msg('block_peek_force_fe_option'), 1);


$content = '';
$content .= $form->getMessage();
$content .= $form->get();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('block_peek_settings'), false);
$fragment->setVar('body', $content, false);

echo $fragment->parse('core/page/section.php');
