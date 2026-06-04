<?php

namespace FriendsOfRedaxo\BlockPeek;

use rex;
use rex_addon;
use rex_config;
use rex_extension;
use rex_extension_point;
use rex_file;
use rex_sql;
use rex_template;
use rex_template_cache;

class TemplateInstaller
{
    public const KEY = 'block_peek_internal';
    public const NAME = '⚠️ BlockPeek (do not delete)';
    private const ATTRIBUTES_JSON = '{"ctype":{"1":{"name":"Default"}}}';

    /**
     * Idempotent. Returns the rex_template row id.
     * - If a row with key 'block_peek_internal' exists: leave it alone, return its id.
     * - Otherwise: build content (migrated from rex_config if a customized old template
     *   exists, else from templates/default.php), INSERT, populate caches, fire
     *   TEMPLATE_ADDED, return the new id.
     */
    public static function seedOrMigrate(): int
    {
        $existing = rex_template::forKey(self::KEY);
        if ($existing !== null) {
            return $existing->getId();
        }

        return self::insertTemplate(self::buildContent());
    }

    /**
     * Defensive self-heal — called by Generator if forKey() returns null at preview time
     * (i.e., admin deleted the row directly). Re-seeds with DEFAULT content (migration
     * sources are gone by this point).
     */
    public static function ensureExists(): int
    {
        $existing = rex_template::forKey(self::KEY);
        if ($existing !== null) {
            return $existing->getId();
        }

        return self::insertTemplate(self::loadDefaultContent());
    }

    private static function insertTemplate(string $content): int
    {
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('template'));
        $sql->setValue('name', self::NAME);
        $sql->setValue('key', self::KEY);
        $sql->setValue('content', $content);
        $sql->setValue('active', 0);
        $sql->setValue('attributes', self::ATTRIBUTES_JSON);
        $sql->addGlobalCreateFields();
        $sql->addGlobalUpdateFields();
        $sql->insert();

        $id = (int) $sql->getLastId();

        rex_template_cache::generate($id);
        rex_template_cache::generateKeyMapping();
        rex_extension::registerPoint(new rex_extension_point('TEMPLATE_ADDED', '', ['id' => $id]));

        return $id;
    }

    /**
     * Build template content for first-time install. Migrates customized rex_config['template']
     * (with assets_head/assets_body inlined) if present and non-default; otherwise returns
     * the shipped default.
     */
    private static function buildContent(): string
    {
        $oldTemplate = rex_config::get('block_peek', 'template', null);
        $oldHead = (string) rex_config::get('block_peek', 'assets_head', '');
        $oldBody = (string) rex_config::get('block_peek', 'assets_body', '');

        $isDefault = $oldTemplate === null
            || $oldTemplate === ''
            || $oldTemplate === '{{block_peek_content}}';

        if ($isDefault) {
            return self::loadDefaultContent();
        }

        $content = (string) $oldTemplate;
        $content = str_replace('{{block_peek_content}}', 'BLOCK_PEEK_CONTENT', $content);

        if ($oldHead !== '') {
            $content = preg_replace('/<\/head>/i', $oldHead . "\n</head>", $content, 1) ?? $content;
        }
        if ($oldBody !== '') {
            $content = preg_replace('/<\/body>/i', $oldBody . "\n</body>", $content, 1) ?? $content;
        }

        return $content;
    }

    private static function loadDefaultContent(): string
    {
        $path = rex_addon::get('block_peek')->getPath('templates/default.php');
        $content = rex_file::get($path);
        if (!is_string($content) || $content === '') {
            return "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"UTF-8\">\n</head>\n<body>\nBLOCK_PEEK_CONTENT\n</body>\n</html>\n";
        }
        return $content;
    }
}
