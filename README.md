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

## Installation

Einfach das Addon über den REDAXO Installer installieren, Template an eigene Wünsche anpassen (oder einfach das eigene Standard-Template einbinden) und die BlockPeek-Einstellungen konfigurieren. _Fetich_!

## Konfiguration

In den Einstellungen des Addons kann ein HTML-Template hinterlegt werden, das für die Vorschau der Slices verwendet wird. Dabei steht der Platzhalter `{{block_peek_content}}` zur Verfügung, der durch den jeweiligen Slice-Inhalt ersetzt wird.

Im Template sollten die CSS-Dateien und ggf. JavaScript-Dateien eingebunden werden, die für die korrekte Darstellung des Inhalts notwendig sind.

Das Template kann wie ein normales Redaxo-Template genutzt werden, inklusive aller REDAXO-Variablen und -Funktionen (inkl. PHP Code).

Zusätzlich können folgende Konfigurationsoptionen angepasst werden:

- **Iframe Mindesthöhe:** Die minimale Höhe des Iframes in Pixeln (Standard: 300).
- **Iframe Zoom-Faktor:** Der Zoom-Faktor des Iframes (Standard: 0.5).
- **Cache Modus:** Legt fest, ob die generierten Vorschauen zwischengespeichert werden sollen (automatisch, aktiviert, deaktiviert).
- **Cache TTL:** Die Zeit in Sekunden, wie lange eine Vorschau im Cache gespeichert bleibt (Standard: 3600

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
