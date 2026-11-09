<?php

declare(strict_types=1);

use FriendsOfRedaxo\BlockPeek\Picker\SvgValidator as V;

// Run: php -d zend.assertions=1 bin/console massif:exec-php "require 'src/addons/block_peek/tests/SvgValidatorTest.php';"
if (ini_get('zend.assertions') !== '1') {
    exit("Run with -d zend.assertions=1\n");
}

assert(V::extract("prose <svg a></svg> more </svg> end") === '<svg a></svg> more </svg>');
assert(V::extract('no svg') === null);

$wrap = static fn (string $inner, string $vb = '0 0 1440 900'): string => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="' . $vb . '">' . $inner . '</svg>';

assert(V::errors($wrap('<rect width="10" height="10"/><use href="#a"/>')) === []);
$bad = [
    $wrap('<rect/>', '0 0 1440 760'),
    $wrap('<script>1</script>'),
    $wrap('<foreignObject/>'),
    $wrap('<image href="x.png"/>'),
    $wrap('<style>a{}</style>'),
    $wrap('<text>x</text>'),
    $wrap('<rect onload="x()"/>'),
    $wrap('<a href="https://e.com"/>'),
    $wrap('<a xlink:href="javascript:1"/>'),
    '<svg viewBox="0 0 1440 900"><rect>',
];
foreach ($bad as $i => $s) {
    assert(V::errors($s) !== [], "case $i");
}
