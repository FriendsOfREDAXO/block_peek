<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\BlockPeek\Picker;

use DOMDocument;
use DOMElement;

final class SvgValidator
{
    private const FORBIDDEN = ['script', 'foreignobject', 'image', 'style', 'text', 'set', 'animate', 'animatemotion', 'animatetransform', 'discard'];

    public static function extract(string $raw): ?string
    {
        // greedy: first <svg to last </svg>
        return preg_match('/<svg\b.*<\/svg>/s', $raw, $m) === 1 ? $m[0] : null;
    }

    /** @return list<string> */
    public static function errors(string $svg): array
    {
        if (trim($svg) === '') {
            return ['empty SVG'];
        }
        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        try {
            $ok = $doc->loadXML($svg, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if ($doc->doctype !== null) {
            return ['DOCTYPE not allowed'];
        }
        $root = $ok ? $doc->documentElement : null;
        if (!$root instanceof DOMElement) {
            return ['not well-formed XML'];
        }

        $errors = [];
        if ($root->localName !== 'svg') {
            $errors[] = 'root must be <svg>';
        }
        if ($root->getAttribute('viewBox') !== '0 0 1440 900') {
            $errors[] = 'viewBox must be "0 0 1440 900"';
        }
        foreach ($doc->getElementsByTagName('*') as $el) {
            if ($el->namespaceURI !== 'http://www.w3.org/2000/svg') {
                $errors[] = 'elements must be in the SVG namespace';
            }
            $name = strtolower($el->localName);
            if (in_array($name, self::FORBIDDEN, true)) {
                $errors[] = "forbidden element <$name>";
            }
            foreach ($el->attributes as $attr) {
                $an = strtolower($attr->localName);
                if (str_starts_with($an, 'on')) {
                    $errors[] = "event attribute $an";
                } elseif ($an === 'href' && !str_starts_with($attr->value, '#')) {
                    $errors[] = 'external href';
                }
            }
        }
        return array_values(array_unique($errors));
    }
}
