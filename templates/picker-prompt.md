You draw an abstract SVG WIREFRAME of a REDAXO 5 module's frontend output. It is shown as a THUMBNAIL (about 180-320 px wide) in a backend "module picker", so editors recognise at a glance what each module looks like and what makes it different from the others. Recognisability first, then proportions; details don't matter.

Module code: read `{module_dir}/input.php` and `{module_dir}/output.php` (module "{module_name}"). Read these for fragments, component CSS and design tokens (globs, relative to the working directory): {context_paths}. Utility classes (e.g. Tailwind) may be used inline. Do not query the database. {reference_image_sentence}


SHOW BEHAVIOUR, not just layout (read the JS contract named in the output, e.g. assets/js/components/*.js, and the CSS for position: sticky/fixed, overflow, animation):
- Slider/carousel: draw the active slide only, plus its pagination/arrows; they signal that there are more slides.
- Sticky/fixed bar or in-page navigation: draw the bar at the TOP of the canvas (zoomed 1.5-2x), the bar itself at least 10 % of the canvas height, active item in the accent colour, and the content it navigates as GHOST below it (headings of the sections it links to). It must read as "navigation above content", never as a band in the middle of a page.
- Scrolling/marquee band: let the items run off both edges.
- Staggered/offset grids: keep the offsets, they are the module's signature.
- Ignore reveal/parallax animations.
- SKIP decorative overlays entirely: scrims, gradients, shadows, halos, blurs. Never imitate them with stepped or banded shapes; a flat surface is correct.

{style_reference_block}

CANVAS (fixed): viewBox="0 0 1440 900", width="1440" height="900". First work out the section's real height H at 1440 px desktop width (paddings, type sizes, aspect ratios; full-viewport sections: H = 810). Then compose:
- 600 <= H <= 1100: draw the section at 1:1 from the top. If it ends before 900, fill the rest with GHOST context (a hint of the next section). If it is taller, let it run off the bottom edge, but keep its defining features (headline, first row) visible.
- H > 1100: condense to fit: fewer repeated rows/items, keep the defining structure.
- H < 600 (bars, bands, strips): ZOOM in (scale 1.5-3 via a <g transform>) on a representative part so the module itself is at least 10 % of the canvas height and, together with its zoomed part, fills at least 45 %, and show GHOST context that explains its role (e.g. content below a sticky bar, the sections a band sits between). The module must stand out clearly from the ghost.
- GHOST = page background plus simple shapes in the ink colour at opacity .07-.10, never the accent colour.

GEOMETRY: images use the aspect ratios from the code (`ratio` params, `aspect-*` classes, CSS), sized and placed like the layout; respect grid columns, gaps and paddings (fluid values: use the 1440 end).

VISUAL VOCABULARY (strict):
- Section background: the surface colour of the module's theme (from tokens; the typical configuration decides the theme). The brand accent from tokens only where the module uses it (buttons, accents, script lines).
- Image: rect in a mid tone between surface and ink; one "mountain + sun" glyph centred in the rect, size 18 % of its shorter side, lighter tone.
- Text, no glyphs: headline line = bar, height 70 % of the type role's font-size, rx = height/2, widths varying 60-95 % of the column; body line = bar height 14, rx 7, gap 14, last line shorter, at most 3 lines per paragraph; eyebrow = bar height 10.
- Button = pill (outline stroke 3 or filled, as in the module) with one short bar inside. Icons = circles of at least 24.
- Bars in the theme's ink colour: headlines opacity .85, body .45.
- Minimum element size 10 units after any zoom.
- No <text>, <script>, <foreignObject>, <image>, <style>, external refs, filters, masks or gradients.

Return ONLY one JSON object, no prose, no code fence: {"description": "<one sentence in {language}, max 120 characters, telling an editor what the module is for>", "svg": "<the SVG markup>"}
