<?php

declare(strict_types=1);

use FriendsOfRedaxo\BlockPeek\Picker\PromptBuilder as P;

// Run: php -d zend.assertions=1 bin/console massif:exec-php "require 'src/addons/block_peek/tests/PromptBuilderTest.php';"
if (ini_get('zend.assertions') !== '1') {
    exit("Run with -d zend.assertions=1\n");
}

$vars = ['module_dir' => 'src/modules/x', 'module_name' => 'Hero', 'context_paths' => ['a/**', 'b.css'], 'style_reference' => '<svg/>', 'reference_image' => 'docs/ref.png', 'language' => 'de'];
$d = P::defaultPrompt();
$out = P::build($d, $vars);
foreach (['module_dir', 'module_name', 'context_paths', 'style_reference_block', 'reference_image_sentence', 'language'] as $k) {
    assert(!str_contains($out, '{' . $k . '}'), $k);
}
assert(str_contains($out, 'a/**, b.css'));
assert(str_contains($out, 'docs/ref.png'));

$none = P::build($d, ['style_reference' => '', 'reference_image' => ''] + $vars);
assert(str_contains($none, 'STYLE REFERENCE: none'));
assert(!str_contains($none, 'screenshot of another page'));

assert(P::contextPaths("a\n\n b \n") === ['a', 'b']);
assert(str_contains($d, 'viewBox="0 0 1440 900"') && str_contains($d, '10 % of the canvas height') && str_contains($d, '"description"'));
