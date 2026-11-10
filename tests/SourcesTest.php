<?php

declare(strict_types=1);

use FriendsOfRedaxo\BlockPeek\Picker\Sources;

// Run: php -d zend.assertions=1 bin/console massif:exec-php "require 'src/addons/block_peek/tests/SourcesTest.php';"
if (ini_get('zend.assertions') !== '1') {
    exit("Run with -d zend.assertions=1\n");
}

$base = rex_path::addonCache('block_peek', 'test-sources/');
rex_dir::delete($base);

try {
    foreach (['a.php', 'sub/b.css', '.hidden/c.php', 'excluded/d.php'] as $file) {
        rex_file::put($base . $file, $file);
    }
    rex_dir::create($base . 'dir');
    $x = [$base . 'excluded/'];

    assert(Sources::collect('a.php', $base, $x) === [], 'non-array input is ignored');
    assert(Sources::collect(null, $base, $x) === []);
    $reported = ['a.php', './sub/b.css', $base . 'a.php', '../a.php', 'nope.php', 'dir', '.hidden/c.php', 'excluded/d.php', 42, 'sub/*.css'];
    assert(array_keys(Sources::collect($reported, $base, $x)) === ['a.php', 'sub/b.css'], 'only existing, contained, visible, non-excluded files survive, deduped');
    assert(Sources::collect(['a.php'], $base, $x)['a.php'] === sha1_file($base . 'a.php'));

    foreach (range(0, 59) as $i) {
        rex_file::put($base . "n$i.php", (string) $i);
    }
    assert(count(Sources::collect(array_map(static fn (int $i): string => "n$i.php", range(0, 59)), $base, $x)) === Sources::MAX, 'sources are capped');

    $s = Sources::collect(['a.php', 'sub/b.css'], $base, $x);
    assert(!Sources::changed($s, $base));
    rex_file::put($base . 'a.php', 'changed');
    assert(Sources::changed($s, $base), 'a modified source counts as changed');
    $s = Sources::collect(['a.php', 'sub/b.css'], $base, $x);
    unlink($base . 'sub/b.css');
    assert(Sources::changed($s, $base), 'a deleted source counts as changed');
    assert(!Sources::changed([], $base));
} finally {
    rex_dir::delete($base);
}
