# BlockPeek 🥽 für REDAXO 5

<img width="1344" height="768" alt="block_peek" src="https://github.com/user-attachments/assets/719a2a26-7759-47e3-a287-c02b4fb0bccf" />

Tauscht im REDAXO Backend den Slice-Output durch eine Vorschau des jeweiligen Inhalts aus dem Frontend aus.
Damit das Ganze funktioniert, wird ein HTML-Template benötigt, das in den Addon-Einstellungen hinterlegt werden kann.
Ausserdem sollte der Slice-Output modular aufgebaut sein, damit die Vorschau auch wirklich den Inhalt des jeweiligen Slices darstellt.

## Features

- Vorschau des Slice-Inhalts im REDAXO Backend
- Verhindert Interaktion im Vorschau-Inhalt (keine Links, Formulare etc. anklickbar)
- Konfigurierbares HTML-Template für die Vorschau
- Iframe-basierte Darstellung der Vorschau
- Anpassbare Iframe-Größe und Zoom-Faktor
- Caching der generierten Vorschauen zur Performance-Optimierung
- Extension Point `BLOCK_PEEK_OUTPUT` zur weiteren Anpassung der Ausgabe
- Extension Point `BLOCK_PEEK_ACTIVE`, um die Vorschau für einzelne Slices abzuschalten

## Installation

Einfach das Addon über den REDAXO Installer installieren, Template an eigene Wünsche anpassen (oder einfach das eigene Standard-Template einbinden) und die BlockPeek-Einstellungen konfigurieren. _Fetich_!

## Konfiguration

In den Einstellungen des Addons kann ein HTML-Template hinterlegt werden, das für die Vorschau der Slices verwendet wird. Dabei steht der Platzhalter `BLOCK_PEEK_CONTENT` zur Verfügung, der durch den jeweiligen Slice-Inhalt ersetzt wird.

Im Template sollten die CSS-Dateien und ggf. JavaScript-Dateien eingebunden werden, die für die korrekte Darstellung des Inhalts notwendig sind.

Das Template wird intern als verstecktes REDAXO-Template (Key: `block_peek_internal`) gespeichert und durchläuft die normale REDAXO-Renderpipeline — inklusive aller REDAXO-Variablen, `redaxo://` Links und PHP Code. `REX_ARTICLE[...]` solltest du nicht verwenden, ausser du willst tatsächlich alle Slices des Artikels rendern.

Zusätzlich können folgende Konfigurationsoptionen angepasst werden:

- **Iframe Mindesthöhe:** Die minimale Höhe des Iframes in Pixeln (Standard: 300).
- **Iframe Zoom-Faktor:** Der Zoom-Faktor des Iframes (Standard: 0.5).
- **Cache Modus:** Legt fest, ob die generierten Vorschauen zwischengespeichert werden sollen (automatisch, aktiviert, deaktiviert).
- **Cache TTL:** Die Zeit in Sekunden, wie lange eine Vorschau im Cache gespeichert bleibt (Standard: 3600).

## Tailwind 4 / `@source` Discovery

Das Vorschau-Template wird als verstecktes REDAXO-Template (Key: `block_peek_internal`) gespeichert. Damit Tailwind 4 die im Template verwendeten Utility-Klassen findet, empfehlen wir das [`developer`](https://github.com/redaxo/developer) Addon — es synchronisiert `rex_template`-Datensätze als Dateien nach `<data>/addons/developer/templates/<Name> [<id>]/template.php` (`<data>` ist je nach Installation entweder `redaxo/data/` oder `var/data/`). Anschließend einfach eine `@source`-Direktive in dein CSS aufnehmen:

```css
@source "../../path/to/data/addons/developer/templates/**/*.php";
```

Ohne das `developer` Addon liegt das Template ausschliesslich in der Datenbank und Tailwind kann es nicht scannen. Workarounds: das `developer` Addon installieren (empfohlen) oder eine Tailwind-Safelist für die im Template verwendeten Klassen pflegen.

## Extension Points

Das Addon stellt den Extension Point `BLOCK_PEEK_OUTPUT` zur Verfügung, mit dem die Ausgabe der Vorschau weiter angepasst werden kann.

Zum Beispiel in der `project` Addon `boot.php` folgendes einfügen:

```php
rex_extension::register('PACKAGES_INCLUDED', function (rex_extension_point $ep) {
    rex_extension::register('BLOCK_PEEK_OUTPUT', function (rex_extension_point $ep) {
        $html = $ep->getSubject();
        $sliceId = $ep->getParam('slice_id', 0);
        // Beispiel: Füge eine benutzerdefinierte Nachricht am Ende der Vorschau hinzu
        $html .= '<div style="position: fixed; bottom: 10px; right: 10px; background: rgba(0,0,0,0.5); color: white; padding: 5px; border-radius: 3px;">Slice ID: ' . $sliceId . '</div>';
        $ep->setSubject($html);
    });
});
```

### BLOCK_PEEK_ACTIVE

Mit `BLOCK_PEEK_ACTIVE` lässt sich die Vorschau für einzelne Slices abschalten. Gibt ein Handler `false` zurück, bleibt die normale REDAXO-Vorschau stehen (die Modul-Ausgabe, im Backend gerendert); für diesen Slice wird kein iframe erzeugt und nichts gecacht. Sinnvoll für Module ohne sichtbares Frontend-Markup wie Ankerpunkte, HTML-Code oder eingebundene Artikel, die im Backend lieber einen kurzen Hinweis zeigen als einen leeren Kasten.

Parameter: `article_id`, `clang`, `ctype`, `module_id`, `slice_id`, `revision` (wie bei `SLICE_BE_PREVIEW`).

```php
rex_extension::register('PACKAGES_INCLUDED', function () {
    rex_extension::register('BLOCK_PEEK_ACTIVE', function (rex_extension_point $ep) {
        $module = rex_module::forId((int) $ep->getParam('module_id'));
        // Beispiel: keine iframe-Vorschau für das Modul mit dem Key "anchor"
        if ($module && 'anchor' === $module->getKey()) {
            return false;
        }
    });
});
```

## Tipps und Tricks

- Achte darauf, dass das HTML-Template korrekt aufgebaut ist und alle notwendigen Ressourcen (CSS, JS) eingebunden sind.
- Nutze den Extension Point `BLOCK_PEEK_OUTPUT`, um spezielle Anpassungen an der Vorschau vorzunehmen.
- in der Modul-Ausgabe kann man mit `rex::isBackend()` zum Beispiel CSS-Klassen nur im Backend zuweisen. So kann man die Modul-Ausgabe für die Vorschau optimieren.

---

Schreibt auftretende Fehler, Notices und Wünsche als Issue auf [Github](https://github.com/FriendsOfREDAXO/block_peek/issues)

---

Das Changelog: [CHANGELOG.md](CHANGELOG.md)

---

## Lizenz

[The MIT License (MIT)](LICENSE.md)

## Credits

- [FriendsOfREDAXO](https://github.com/FriendsOfREDAXO)
- Project Lead: [Yves Torres](https://github.com/ynamite)
