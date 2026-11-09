<?php

declare(strict_types=1);

use FriendsOfRedaxo\BlockPeek\Picker\PreviewStore as S;

// Run: php -d zend.assertions=1 bin/console massif:exec-php "require 'src/addons/block_peek/tests/PreviewStoreTest.php';"
if (ini_get('zend.assertions') !== '1') {
    exit("Run with -d zend.assertions=1\n");
}

$dir = rex_path::addonCache('block_peek', 'test-store/');
rex_dir::delete($dir);

assert(S::slug(6, null) === '6' && S::slug(6, '') === '6' && S::slug(6, 'hero') === 'hero');
assert(S::hash('a', 'b') !== S::hash('ab', ''));

$s = new S($dir);
$e = ['id' => 6, 'key' => null, 'name' => 'Hero', 'description' => 'd', 'locked' => false, 'hash' => 'h', 'model' => 'm', 'generated' => 1, 'status' => 'ok'];
$s->put('6', $e, '<svg/>');
assert($s->get('6')['name'] === 'Hero' && $s->svg('6') === '<svg/>');
$s->put('6', ['name' => 'Hero2'] + $e, null);
assert($s->svg('6') === '<svg/>' && $s->get('6')['name'] === 'Hero2');
$s->setDescription('6', 'Neu', true);
assert($s->get('6')['locked'] === true && $s->get('6')['description'] === 'Neu');

$s->put('team', ['id' => 22, 'key' => 'team', 'name' => 'Team'] + $e, '<svg id="t"/>');
$js = $s->forJs([['id' => 22, 'key' => 'team'], ['id' => 99, 'key' => null]]);
assert(isset($js[22]) && $js[22]['svg'] === '<svg id="t"/>' && !isset($js[99]));

$json = (string) file_get_contents($dir . 'previews.json');
assert(str_ends_with($json, "}\n"));
assert(array_map('strval', array_keys($s->all())) === ['6', 'team']);

assert($s->removeOrphans(['team']) === ['6'] && $s->svg('6') === null);
rex_dir::delete($dir);
