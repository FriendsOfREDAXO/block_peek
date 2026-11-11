<?php

declare(strict_types=1);

use FriendsOfRedaxo\BlockPeek\Picker\Sources;

// Run: php -d zend.assertions=1 bin/console massif:exec-php "require 'src/addons/block_peek/tests/SourcesTest.php';"
if (ini_get('zend.assertions') !== '1') {
    exit("Run with -d zend.assertions=1\n");
}

$base = rex_path::addonCache('block_peek', 'test-sources/');
$outside = rex_path::addonCache('block_peek', 'test-sources-outside.txt');
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

    // Big files (bundles, data dumps) are not fingerprinted; hashes are shared per check.
    rex_file::put($base . 'big.js', str_repeat('a', Sources::MAX_BYTES + 1));
    assert(Sources::collect(['big.js', 'a.php'], $base, $x) === ['a.php' => sha1_file($base . 'a.php')], 'files above MAX_BYTES are skipped');
    $s = Sources::collect(['a.php'], $base, $x);
    $hashes = [];
    assert(!Sources::changed($s, $base, $x, $hashes) && count($hashes) === 1, 'changed() records each hash it computes');
    $hashes = array_fill_keys(array_keys($hashes), 'memo');
    assert(Sources::changed($s, $base, $x, $hashes), 'changed() reuses recorded hashes instead of hashing again');

    // Review fixes: malformed paths never throw; symlinks are checked by reported and resolved path; rechecks stay inside the project.
    assert(array_keys(Sources::collect(["a\0.php", 'a.php'], $base, $x)) === ['a.php'], 'a NUL byte must not fail the generation');
    rex_file::put($base . 'sub/b.css', 'b');
    symlink($base . 'sub/b.css', $base . '.hidden/link.css');
    assert(Sources::collect(['.hidden/link.css'], $base, $x) === [], 'a hidden reported path is rejected even if its target is visible');
    rex_file::put($outside, 'b');
    $s = Sources::collect(['sub/b.css'], $base, $x);
    unlink($base . 'sub/b.css');
    symlink($outside, $base . 'sub/b.css');
    assert(Sources::changed($s, $base), 'a source replaced by a symlink out of the project counts as changed');
    assert(Sources::changed(['../' . basename($outside) => sha1_file($outside)], $base), 'stored paths outside the project count as changed');
} finally {
    rex_dir::delete($base);
    rex_file::delete($outside);
}
