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
- Extension Point `BLOCK_PEEK_SLICE_ENABLED`, um die Vorschau für einzelne Slices abzuschalten

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

## Modul-Picker

Der optionale Modul-Picker ersetzt beim Hinzufügen eines Blocks das Modul-Dropdown durch eine durchsuchbare Auswahl mit SVG-Wireframes und Kurzbeschreibungen. Namen, Beschreibungen und die bestehenden Modulgruppen helfen bei der Auswahl. In der Konfiguration **Modul-Picker aktivieren** einschalten; standardmässig ist er aus. Aus = natives Dropdown, keine Picker-JS/CSS-Dateien oder Vorschaudaten werden geladen, keine Generierung.

### Provider

`auto` prüft in dieser Reihenfolge:

1. Lokale **Codex-CLI**, danach **Claude-CLI**; ausführbare Dateien werden in `~/.local/bin`, `~/.local/shims`, `/opt/homebrew/bin`, `/usr/local/bin` und im `PATH` gesucht. Unter PHP-FPM/Herd kann der Suchpfad vom Terminal abweichen: einen absoluten CLI-Pfad unter `picker_cli_path` hinterlegen. In `auto` muss dessen Dateiname zum Provider passen (`codex` bzw. `claude`); alternativ den Provider explizit wählen.
2. Optional **ai_platform** mit dem ausgewählten Profil (`picker_ai_profile`). Im Profil **Max Tokens >= 8192** setzen; für ein Referenzbild ein bildfähiges Profil verwenden.
3. Ohne verfügbaren Provider bzw. bei `none` werden keine neuen Thumbnails erzeugt. Bereits gespeicherte Vorschauen bleiben nutzbar, fehlende bleiben leer.

Ein leeres `picker_command` verwendet das jeweilige CLI-Preset. Ein eigener Befehl wird für die jeweils ausgewählte CLI verwendet; in `auto` kann das Codex oder Claude sein. Bei einem eigenen `picker_command` den Provider deshalb explizit auf `codex` oder `claude` setzen. Eigene Befehle erhalten den Prompt auf stdin und müssen das Ergebnis (`description` und `svg` als JSON) auf stdout ausgeben. Verfügbare Befehlsplatzhalter: `{bin}`, `{model}`, `{effort}`, `{image_args}`, `{out}`, `{tmp}`. Platzhalter nur unquoted verwenden, niemals innerhalb einfacher oder doppelter Anführungszeichen: BlockPeek übernimmt das Shell-Escaping. Der Befehl muss mit einem ausführbaren Kommando beginnen; Umgebungsvariablen etwa mit `env VAR=wert {bin} …` setzen, nicht mit `VAR=wert {bin} …`.

### Generierung und Verwaltung

Im REDAXO-Projektverzeichnis:

```bash
bin/console block_peek:generate [--module=ID] [--force]
```

Ohne Optionen werden fehlende, fehlgeschlagene oder veraltete Vorschauen generiert (Modul-Input/-Output oder eine gemeldete Quelldatei geändert). `--module=ID` beschränkt den Lauf auf ein Modul, `--force` generiert auch unveränderte Vorschauen neu. Bei Fehlern bleibt das bisherige SVG erhalten; weitere Module werden verarbeitet. Die Abschlusszeile nennt `Generated`, `skipped` und `failed` — auch bei einzelnen Fehlschlägen kann der Exit-Code 0 sein.

Die Admin-Unterseite **BlockPeek → Modul-Picker** zeigt Vorschauen und Status, startet die Generierung im Hintergrund und erlaubt das Bearbeiten und Sperren von Beschreibungen gegen AI-Überschreiben. Ohne verfügbaren Provider bleibt sie schreibgeschützt. Falls der Hintergrundstart unter PHP-FPM/Herd scheitert, `picker_php_path` auf die PHP-CLI setzen. Gleichzeitige Generierungsläufe werden per Lock verhindert.

Bei jeder Generierung meldet die AI die Projektdateien, auf denen die Vorschau beruht (z. B. Fragmente, Klassen, CSS/JS); BlockPeek speichert deren Fingerprints. Eine Vorschau gilt als **nicht aktuell**, wenn sie fehlt, ihre Generierung fehlgeschlagen ist oder sich das Modul oder eine dieser Dateien geändert hat; „Fehlende/veraltete generieren“ erneuert genau diese. Vorschauen aus älteren Versionen ohne gespeicherte Dateien gelten als nicht aktuell und werden bei der nächsten Generierung einmal erneuert. Admins sehen nicht aktuelle Vorschauen – sofern ein Provider verfügbar ist – als Hinweis oben im Modul-Picker (mit Punkt neben den betroffenen Modulen) und als Meldung auf der Struktur-Seite, bis alle Vorschauen aktuell sind, jeweils mit Link zum Neu-Generieren.

### Prompt und Kontext

`picker_prompt` ist in den Einstellungen editierbar; leer verwendet `templates/picker-prompt.md`, einsehbar über **Standard anzeigen**. Platzhalter: `{module_dir}` (temporäres Verzeichnis mit `input.php` und `output.php`), `{module_name}`, `{context_paths}`, `{style_reference_block}`, `{reference_image_sentence}`, `{language}`. Stilreferenz und Referenzbild lassen sich separat wählen; der Bildpfad ist relativ zum Projektverzeichnis. Eine leere Sprache verwendet `rex::getProperty('lang')`.

`picker_context_paths` enthält einen Glob pro Zeile, relativ zum Projektverzeichnis. MASSIF-Defaults:

```text
src/fragments/**
src/assets/css/components/**
src/assets/css/theme/tokens.css
src/assets/css/utils/fluid-grid.css
```

Lokale CLIs lesen die im Prompt angegebenen Pfade selbst. Für `ai_platform` bettet `ContextInliner` Modulcode und passende Kontextdateien ein (maximal 200.000 Inhaltsbytes): nur Textquellen mit den Endungen `css`, `scss`, `sass`, `less`, `js`, `mjs`, `cjs`, `ts`, `php`, `html`, `htm`, `twig`, `md`; Dotfiles und versteckte Verzeichnisse werden übersprungen, aufgelöste Pfade müssen innerhalb des Projektverzeichnisses bleiben. `/**` liest rekursiv, andere Muster verwenden normales Glob-Matching.

### Speicherung und Deployment

SVGs und `previews.json` liegen im Addon-Datenverzeichnis, bei MASSIF unter `var/data/addons/block_peek/previews/`. Dieses Verzeichnis committen und bei Migrationen bzw. Deployments mitnehmen. Modul-Keys dienen als stabile Zuordnung, ohne Key wird die Modul-ID verwendet; diese muss im Zielsystem passen. Nach Änderungen am Modul oder an gemeldeten Quelldateien erneut generieren; nach Prompt-, Stil- oder Kontextänderungen `--force` verwenden.

Falls `.gitignore` die Regel `var/data/addons/*/*` enthält, danach freigeben:

```gitignore
!var/data/addons/block_peek/previews/
!var/data/addons/block_peek/previews/**
```

Deployer-Beispiel für einen bereits konfigurierten lokalen Build-Host `local`, der im REDAXO-Projektverzeichnis arbeitet und das Release lokal vorbereitet. Nach dem Anlegen des Release-Verzeichnisses und vor dessen Upload ausführen; die CLI bleibt auf dem lokalen Host. Neue oder geänderte Vorschauen werden anschliessend lokal committet, damit das Arbeitsverzeichnis sauber bleibt:

```php
task('block_peek:previews', static function () {
    on(host('local'), static function () {
        $dir = 'var/data/addons/block_peek/previews';
        if (!str_contains(run('bin/console list --raw'), 'block_peek:generate')) {
            info('BlockPeek nicht installiert oder nicht aktiviert, Vorschauen übersprungen.');
            return;
        }
        try {
            $output = run('bin/console block_peek:generate', ['timeout' => null]);
            writeln($output);
            if (preg_match('/failed: [1-9]/', $output)) {
                warning('BlockPeek: einzelne Vorschauen fehlgeschlagen.');
            }
        } catch (\Throwable $e) {
            warning('BlockPeek: ' . $e->getMessage());
        }
        if (!test("[ -d $dir ]")) {
            return;
        }
        // Nur das Vorschau-Verzeichnis committen, andere (auch gestagte) Änderungen bleiben unberührt; ohne Änderungen kein Commit.
        try {
            run("git add -A $dir && (git diff --cached --quiet -- $dir || git commit -q --no-verify -m 'chore: update block_peek previews' -- $dir)");
        } catch (\Throwable $e) {
            warning('BlockPeek: Vorschauen nicht committet: ' . $e->getMessage());
        }
        run('mkdir -p {{release_path}}/var/data/addons/block_peek');
        run("cp -R $dir {{release_path}}/var/data/addons/block_peek/");
    });
});
after('deploy:update_code', 'block_peek:previews');
```

Den Hook an die eigene lokale Build-/Upload-Reihenfolge anpassen. Generierungsfehler warnen, vorhandene Vorschauen werden weiter ins Release kopiert. Der Commit wird nicht gepusht; `--no-verify` verhindert, dass Git-Hooks (z. B. Formatter) die generierten Dateien während des Deployments verändern.

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

### BLOCK_PEEK_SLICE_ENABLED

Mit `BLOCK_PEEK_SLICE_ENABLED` lässt sich die Vorschau für einzelne Slices abschalten. Gibt ein Handler `false` zurück, bleibt die normale REDAXO-Vorschau stehen (die Modul-Ausgabe, im Backend gerendert); für diesen Slice wird kein iframe erzeugt und nichts gecacht. Sinnvoll für Module ohne sichtbares Frontend-Markup wie Ankerpunkte, HTML-Code oder eingebundene Artikel, die im Backend lieber einen kurzen Hinweis zeigen als einen leeren Kasten.

Parameter: `article_id`, `clang`, `ctype`, `module_id`, `slice_id`, `revision` (wie bei `SLICE_BE_PREVIEW`).

```php
rex_extension::register('BLOCK_PEEK_SLICE_ENABLED', function (rex_extension_point $ep) {
    $module = new rex_module((int) $ep->getParam('module_id'));
    // Beispiel: keine iframe-Vorschau für das Modul mit dem Key "anchor"
    if ('anchor' === $module->getKey()) {
        return false;
    }
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
