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
assert(V::errors('<!DOCTYPE svg [<!ENTITY x "<script>alert(1)</script>">]>' . $wrap('&x;')) === ['DOCTYPE not allowed']);
assert(V::errors($wrap('<rect fill="url(https://x/y)"/>')) === ['external url() reference']);
assert(V::errors($wrap("<rect style=\"fill:url( 'http://x' )\"/>")) === ['external url() reference']);
assert(V::errors($wrap('<rect fill="url(#a)"/>')) === []);
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
    $wrap('<a href="#a"><set attributeName="href" to="javascript:alert(1)"/></a>'),
    $wrap('<animate attributeName="xlink:href" values="javascript:alert(1)"/>'),
    $wrap('<animateMotion/>'),
    $wrap('<animateTransform/>'),
    $wrap('<discard/>'),
    '<svg viewBox="0 0 1440 900"/>',
    '<div xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 900"/>',
    $wrap('<div xmlns="http://www.w3.org/1999/xhtml"/>'),
    '<svg viewBox="0 0 1440 900"><rect>',
];
foreach ($bad as $i => $s) {
    assert(V::errors($s) !== [], "case $i");
}

$previous = libxml_use_internal_errors(false);
try {
    foreach (['', " \n\t", '<svg', $wrap('<rect/>')] as $svg) {
        $errors = V::errors($svg);
        assert(libxml_use_internal_errors() === false, 'validator must restore libxml settings');
        assert(($errors === []) === ($svg === $wrap('<rect/>')));
    }
    libxml_use_internal_errors(true);
    assert(V::errors('') !== []);
    assert(libxml_use_internal_errors() === true);
} finally {
    libxml_use_internal_errors($previous);
}
