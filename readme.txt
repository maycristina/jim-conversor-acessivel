=== Jim - Conversor Acessível ===
Contributors: maycristina
Tags: accessibility, pdf, docx, shortcode, text-to-speech
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converts PDF, Word (.docx) and TXT files into responsive, accessible pages with text-to-speech, publishable via shortcode.

== Description ==

**Jim - Conversor Acessível** lets administrators upload PDF, DOCX or TXT files from the WordPress dashboard and turns each file into a responsive, accessible (WCAG 2.1 AA) HTML page, with:

* Text size, high-contrast and reading theme controls (Light, Sepia, Dark).
* A floating player docked to the bottom of the screen, following Material Design 3 (48dp touch targets, 24dp icons, label-large type, level 2/3 elevation): text size and theme, high contrast, voice, play/pause, speed, stop, and a button to hide the whole bar.
* Text-to-speech using the browser's native Web Speech API (no external API cost).
* Contrast, visible focus and keyboard navigation across all controls.
* Conversions stored in the WordPress database (Custom Post Type), reusable via the `[documento_acessivel id="123"]` shortcode.
* A `[jimca_instalacoes]` shortcode that displays the number of active installs reported by the WordPress.org API (available once the plugin is published in the official directory).

= Requirements =

* PHP 7.4+
* Dependencies installed via Composer (`composer install` in the plugin folder) before activation: `smalot/pdfparser` and `phpoffice/phpword`.

= Supported formats =

* PDF (with extractable text — image-only/scanned PDFs are not supported)
* Word `.docx` (the legacy Word 97-2003 `.doc` format is not supported)
* TXT

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/`.
2. Run `composer install --no-dev` inside the plugin folder.
3. Activate the plugin under **Plugins > Installed Plugins**.
4. Go to **Jim - Conversor Acessível > Novo Documento** to upload a file.
5. Copy the generated shortcode (`[documento_acessivel id="X"]`) and paste it into any page or post.

== Frequently Asked Questions ==

= Does the install counter work before I publish the plugin on WordPress.org? =

No. The `[jimca_instalacoes]` shortcode queries the public WordPress.org API (`api.wordpress.org/plugins/info`), which only has data after the plugin has been submitted to and approved in the official directory. Until then, the shortcode renders blank for visitors (and shows a notice to logged-in administrators).

= Are the original uploaded files kept? =

By default, no — the plugin extracts the content and deletes the original uploaded file. This can be changed under **Jim - Conversor Acessível > Configurações**.

= Can I choose how the converted document looks? =

Yes. Under **Jim - Conversor Acessível > Configurações** you can set a default reading theme (Light, Sepia or Dark) applied to every converted document. Readers can also switch the theme themselves from the reading toolbar on each document — their choice is saved only in their own browser and doesn't change the site-wide default.

= Does the plugin send data to any external server? =

Only one call, and only if you use the `[jimca_instalacoes]` shortcode: a request to WordPress.org's own public API (`api.wordpress.org/plugins/info`) to fetch this plugin's active install count. No data about your site, your documents or your visitors is ever sent anywhere else.

== Screenshots ==

1. A converted document with the floating reading bar: text size and theme, high contrast, voice, play/pause, speed, stop and hide.
2. The same document on a phone, with the bar fitting seven controls at a 48px touch target.
3. The appearance menu open above the bar, following the Material Design 3 menu pattern.
4. The in-plugin Tutorial screen: what the plugin is, how to use it, version and authorship.

== Changelog ==

= 1.0.0 =
* Initial release: PDF/DOCX/TXT conversion, accessible document shortcode with text-to-speech, reading themes (Light/Sepia/Dark), and WordPress.org install-count shortcode.
* Reading controls float at the bottom of the screen while the document is in view, with menus that open above each button; the reader's choices (size, theme, contrast, voice, speed, bar hidden) are remembered per document in their own browser.
* Progress feedback while a document is being converted, and readable messages instead of a raw error page when the server rejects an upload or lacks a required PHP extension.
