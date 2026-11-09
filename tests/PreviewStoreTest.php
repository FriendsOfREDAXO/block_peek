<?php

declare(strict_types=1);

use FriendsOfRedaxo\BlockPeek\Picker\PreviewStore as S;

// Run: php -d zend.assertions=1 bin/console massif:exec-php "require 'src/addons/block_peek/tests/PreviewStoreTest.php';"
if (ini_get('zend.assertions') !== '1') {
    exit("Run with -d zend.assertions=1\n");
}

$dir = rex_path::addonCache('block_peek', 'test-store/');
rex_dir::delete($dir);

try {
    assert(S::slug(6, null) === '6' && S::slug(6, '') === '6' && S::slug(6, 'hero') === 'key-hero');
    assert(S::hash('a', 'b') !== S::hash('ab', ''));

    $s = new S($dir);
    assert($s->all() === []);
    $e = ['id' => 6, 'key' => null, 'name' => 'Hero', 'description' => 'd', 'locked' => false, 'hash' => 'h', 'model' => 'm', 'generated' => 1, 'status' => 'ok'];
    $s->put('key-team', ['id' => 22, 'key' => 'team', 'name' => 'Team'] + $e, '<svg id="t"/>');
    $s->put('6', $e, '<svg/>');
    assert($s->get('6')['name'] === 'Hero' && $s->svg('6') === '<svg/>');
    $s->put('6', ['name' => 'Hero2'] + $e, null);
    assert($s->svg('6') === '<svg/>' && $s->get('6')['name'] === 'Hero2');
    $s->setDescription('6', 'Neu', true);
    assert($s->get('6')['locked'] === true && $s->get('6')['description'] === 'Neu');

    $js = $s->forJs([['id' => 22, 'key' => 'team'], ['id' => 99, 'key' => null]]);
    assert(isset($js[22]) && $js[22]['svg'] === '<svg id="t"/>' && !isset($js[99]));

    $s->put(S::slug(23, '6'), ['id' => 23, 'key' => '6', 'description' => 'Numeric key'] + $e, '<svg id="key-six"/>');
    $js = $s->forJs([['id' => 6, 'key' => null], ['id' => 23, 'key' => '6']]);
    assert(S::slug(23, '6') === 'key-6');
    assert($js === [6 => ['description' => 'Neu', 'svg' => '<svg/>'], 23 => ['description' => 'Numeric key', 'svg' => '<svg id="key-six"/>']], 'numeric module keys must not overwrite ID previews');
    assert($s->removeOrphans(['6', 'key-team']) === ['key-6']);

    $json = (string) file_get_contents($dir . 'previews.json');
    assert(str_ends_with($json, "}\n"));
    assert(array_map('strval', array_keys($s->all())) === ['6', 'key-team']);

    try {
        $s->put('6', ['description' => "\xC3"] + $e, '<svg id="invalid-entry"/>');
        assert(false, 'invalid UTF-8 must throw');
    } catch (JsonException) {
    }
    assert(rex_file::get($dir . 'previews.json') === $json, 'encoding failure must preserve the index');
    assert($s->svg('6') === '<svg/>', 'encoding failure must restore the previous SVG');

    rex_file::put($dir . 'previews.json', "<<<<<<< HEAD\n=======\n>>>>>>> branch\n");
    try {
        $s->all();
        assert(false, 'corrupt JSON must throw');
    } catch (JsonException) {
    }
    rex_file::put($dir . 'previews.json', $json);

    rex_file::delete($dir . '6.svg');
    rex_dir::create($dir . '6.svg');
    try {
        // rex_file's copy fallback can warn for the deliberately blocked destination.
        @$s->put('6', ['hash' => 'new', 'status' => 'failed'] + $e, '<svg id="new"/>');
        assert(false, 'failed SVG write must throw');
    } catch (RuntimeException) {
    }
    assert(rex_file::get($dir . 'previews.json') === $json, 'failed SVG write must preserve the index');
    rex_dir::delete($dir . '6.svg');
    rex_file::put($dir . '6.svg', '<svg/>');

    chmod($dir . 'previews.json', 0444);
    try {
        $s->setDescription('6', 'unwritable', false);
        assert(false, 'failed index write must throw');
    } catch (RuntimeException) {
    } finally {
        chmod($dir . 'previews.json', 0644);
    }
    assert(rex_file::get($dir . 'previews.json') === $json);

    foreach (['6', 'new'] as $slug) {
        $previous = $s->svg($slug);
        chmod($dir . 'previews.json', 0444);
        try {
            $s->put($slug, $e, '<svg id="replacement"/>');
            assert(false, 'failed index write must throw after writing the SVG');
        } catch (RuntimeException) {
        } finally {
            chmod($dir . 'previews.json', 0644);
        }
        assert($s->svg($slug) === $previous, 'failed index write must restore or remove the new SVG');
        assert(rex_file::get($dir . 'previews.json') === $json);
    }

    chmod($dir, 0555);
    try {
        @$s->removeOrphans(['key-team']);
        assert(false, 'failed SVG deletion must throw');
    } catch (RuntimeException) {
    } finally {
        chmod($dir, 0755);
    }
    assert(rex_file::get($dir . 'previews.json') === $json && $s->svg('6') === '<svg/>');

    assert($s->removeOrphans(['key-team']) === ['6'] && $s->svg('6') === null);

    $s->put('layout/hero', $e, '<svg id="layout"/>');
    $s->put('content/hero', $e, '<svg id="content"/>');
    $s->put('layout%2Fhero', $e, '<svg id="percent"/>');
    assert($s->svg('layout/hero') === '<svg id="layout"/>' && $s->svg('content/hero') === '<svg id="content"/>');
    assert(rex_file::get($dir . 'layout%2Fhero.svg') === '<svg id="layout"/>');
    assert($s->svg('layout%2Fhero') === '<svg id="percent"/>');
    assert($s->removeOrphans(['key-team', 'content/hero', 'layout%2Fhero']) === ['layout/hero']);
    assert($s->svg('layout/hero') === null && $s->svg('content/hero') === '<svg id="content"/>' && $s->svg('layout%2Fhero') === '<svg id="percent"/>');
} finally {
    rex_dir::delete($dir);
}
