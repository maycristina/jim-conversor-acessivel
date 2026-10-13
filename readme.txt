=== Jim - Accessible Converter ===
Contributors: maycristina
Tags: accessibility, pdf, docx, shortcode, text-to-speech
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converts PDF, Word (.docx), Markdown and TXT files into responsive, accessible pages with text-to-speech, publishable via shortcode.

== Description ==

**Jim - Accessible Converter** lets administrators upload PDF, DOCX, Markdown (.md) or TXT files from the WordPress dashboard and turns each file into a responsive, accessible (WCAG 2.1 AA) HTML page, with:

* Text size, high-contrast and reading theme controls (Light, Sepia, Dark).
* A floating player docked to the bottom of the screen, following Material Design 3 (48dp touch targets): a navigation menu (search and contents), settings (voice and theme, including high contrast), text size, play/pause, speed (one tap goes to the next value), stop, previous/next part for long documents, and a button to hide the whole bar.
* Text-to-speech using the browser's native Web Speech API (no external API cost, no text sent to third parties).
* Contrast, visible focus and keyboard navigation across all controls.
* A table of contents side panel, built from Word heading styles or the PDF bookmarks (outline).
* Optional removal of the "Página 1, Página 2…" page markers from PDFs, joining sentences split across pages and dropping printed page numbers.
* Two display modes per document, chosen when you import the file or later in the documents list (Quick Edit): **Accessible reader** (default) or **Standard blog**, which adds a reading-time bar with listen and share buttons.
* **Also publish in**: pick posts, pages or a custom post type and each imported file also becomes an item there, with the shortcode already inside, so you stop pasting shortcodes one by one. The address (slug) is the file name; choose the status (publish now, draft, pending, private or schedule) and the categories and tags.
* **Scheduling with [Action Scheduler](https://actionscheduler.org/)**: a scheduled publication is an event you can see, run or cancel under Tools > Scheduled Actions.
* **Guided tours** of the New Document screen (it starts by itself after a fresh installation) and of the Settings screen (assistant and quick texts); both can be opened again from the Tutorial tab. It is keyboard and screen reader friendly.
* Images from PDF and DOCX files are saved to the **Media Library**, linked to the document, with the alternative text written in Word; images without a description are flagged so the author can describe them. An accessible lightbox opens them full size.
* Limits on the number and total size of images, plus time and memory guards, so large illustrated books don't bring the server down. Each image left out is listed with its cause.
* Automatic SEO tags (meta description, Open Graph, Twitter Card, JSON-LD) on the page that contains the shortcode. They are skipped when Yoast SEO, Rank Math, All in One SEO or SEOPress is active.
* A highlighter: readers select text and mark it in one of four colors. Highlights are saved only in the reader's own browser.
* An **activity log** in the settings that follows each conversion live (time, memory, words, images and the cause of each image left out, errors), with CSV export. It never records document text.
* **Translations**: English, Brazilian Portuguese, Spanish, French, Simplified Chinese, Hindi, Russian and German, chosen by the site or user language. Jim is open source: a "Review translation" button in the settings leads to where corrections and new languages can be sent.
* An optional **AI tutor** for readers: a chat bubble on the document page that answers questions about that document, by text or voice. You give it a name, a personality, instructions and a greeting, choose the AI provider (OpenRouter, Claude by Anthropic, OpenAI, Gemini by Google or DeepSeek, with your own encrypted API key) and decide where it appears. You can also choose, rename, add or turn off the quick texts (the suggestions at the start of the chat). Readers can hide it. Off by default.
* **Large files convert in the background**, with a progress bar (page N of M), so a long book no longer ends in a "504 Gateway Time-out" page. If the server stops the conversion (memory or time limit), the screen and the activity log say why.
* A warning when a PDF's text comes out unreadable (fonts without a Unicode map), instead of publishing garbled text silently.
* A "Server requirements" table in the settings, showing the site's PHP limits against the recommended values.
* Conversions stored in the WordPress database (Custom Post Type), reusable via the `[documento_acessivel id="123"]` shortcode.
* A `[jimca_instalacoes]` shortcode that displays the number of active installs reported by the WordPress.org API (available once the plugin is published in the official directory).

= Support the project =

If this project was useful to you, please consider giving the [repository](https://github.com/maycristina/jim-conversor-acessivel) a star on GitHub! It helps the project grow and reach more developers.

= Requirements =

* PHP 7.4+ (8.1 or later recommended)
* Recommended server settings: memory_limit 512 MB (256 MB minimum), max_execution_time 300 s (120 s minimum), upload size 32 MB. See the "Server requirements" table in the plugin settings.
* PHP extensions: zlib, iconv, mbstring (PDF); zip, dom, xml, mbstring (DOCX); gd (optional).
* No external programs are needed: conversion is done in PHP only.

= Supported formats =

* PDF (with extractable text — image-only/scanned PDFs are not supported, there is no OCR)
* Word `.docx` (the legacy Word 97-2003 `.doc` format is not supported)
* Markdown (.md)
* TXT

Up to 20 files (any of these types) can be sent in a batch at a time, within the configured limit; each one is converted in turn.

= Source code and libraries =

The plugin's source code is public: https://github.com/maycristina/jim-conversor-acessivel

The `vendor/` folder contains unmodified, human-readable PHP libraries installed with Composer: [smalot/pdfparser](https://github.com/smalot/pdfparser) and [phpoffice/phpword](https://github.com/PHPOffice/PHPWord) (both LGPL), plus their dependencies. Their exact versions are listed in `vendor/composer/installed.json`.

The same folder holds [Action Scheduler](https://actionscheduler.org/) (woocommerce/action-scheduler, GPLv3), the library WooCommerce and many plugins use to run scheduled tasks. Jim uses it to publish scheduled posts at the chosen time; if WooCommerce or another plugin ships a newer copy, the library loads only the newest one.

== Installation ==

1. In **Plugins > Add New > Upload Plugin**, send the plugin zip (or upload the plugin folder to `wp-content/plugins/`). The libraries it needs are already included; no Composer step is required.
2. Activate the plugin under **Plugins > Installed Plugins**.
3. Go to **Jim - Accessible Converter > New Document** to upload a file. A short guided tour starts by itself the first time; you can open it again from the Tutorial tab.
4. Copy the generated shortcode (`[documento_acessivel id="X"]`) and paste it into any page or post, or use **Also publish in** and let Jim create the post or page for you.

If you are running the code straight from the GitHub repository instead of the zip, run `composer install --no-dev` inside the plugin folder first.

== Frequently Asked Questions ==

= Where does the install counter get its number? =

The `[jimca_instalacoes]` shortcode queries the public WordPress.org API (`api.wordpress.org/plugins/info`) for this plugin's active install count. If the API can't be reached, the shortcode renders blank for visitors (and shows a notice to logged-in administrators).

= Are the original uploaded files kept? =

By default, no — the plugin extracts the content and deletes the original uploaded file. This can be changed under **Jim - Accessible Converter > Settings**.

= Are images included? =

Yes, by default (this can be turned off under **Jim - Accessible Converter > Settings**). Each image becomes an attachment in the Media Library, linked to its document; deleting the document (emptying the trash) deletes its images, and uninstalling the plugin deletes all of them. In DOCX files each image stays where it was in the text and keeps the alternative text written in Word ("Edit Alt Text"); images marked as decorative get an empty `alt`. In PDF files the images of each page are placed after that page's text, with a placeholder description. After converting, the dashboard notice says how many images still need a description — edit the document to describe them.

Supported: JPEG, PNG, GIF and WebP (DOCX; BMP/TIFF are converted to PNG when PHP's GD extension is available), and, in PDFs, JPEG, 8-bit RGB/gray/CMYK, 1/2/4-bit gray and palette (indexed) images, with transparency. JPEG 2000, fax-encoded (CCITT), JBIG2 and inline (BI … EI) PDF images are skipped and counted in the notice, with the cause. The number of images and their total size per document are limited (configurable under Settings).

= How do I publish many imported files without pasting each shortcode? =

On the New Document screen, open **Also publish in** and choose posts, pages or a custom post type. For each file Jim creates an item of that type with the shortcode inside. The item's address (slug) is the file name, and you choose its status and its categories and tags. Only public post types with the editor are listed, so internal ones (field groups, templates and the like) never show up.

= How does scheduling work, and where do I cancel it? =

Choose **Schedule** and set the date and time by the **site clock** (the form shows what time it is on the site, which may differ from your computer). Each file becomes an Action Scheduler event: the document is converted right away and the item is created at the chosen time. When the upload finishes, the notice gives the event's name (for example "Jim import 2026-10-11 15:10 #ab12"). To learn more, run it now or cancel, open **Tools > Scheduled Actions** (with WooCommerce: **WooCommerce > Status > Scheduled Actions**) and search for that name. Only administrators can open that screen. Events run through WP-Cron, so on a site with very little traffic a real server cron job keeps them on time.

= Where is the guided tour? =

It starts by itself the first time someone opens **New Document** after a fresh installation. To open it again, click **Take the guided tour** on that screen, or **Click here to open the tutorial** on the **Tutorial** tab. Press Esc to leave it at any time.

= Can I choose how the converted document looks? =

Yes. Under **Jim - Accessible Converter > Settings** you can set a default reading theme (Light, Sepia or Dark) applied to every converted document. Readers can also switch the theme themselves from the reading toolbar on each document — their choice is saved only in their own browser and doesn't change the site-wide default.

= Does the plugin send data to any external server? =

Only in the cases below, all from your server and all optional. See "Privacy" below.

1. If you use the `[jimca_instalacoes]` shortcode: a request to WordPress.org's public API (`api.wordpress.org/plugins/info`) for this plugin's active install count. Only the plugin's slug is sent.
2. If an administrator saves an API key for an AI provider and presses its "Test connection" button: one fixed sentence, with the key, to that provider only (OpenRouter, Anthropic, OpenAI, Google or DeepSeek).
3. If you turn on the AI tutor: each question a reader asks, the last turns of that reader's conversation and the passages of the document that best match the question go to the AI provider you chose. Nothing is sent until a reader asks something.

Without the tutor, no data about your documents or visitors is sent.

= How does the AI tutor work? =

In **Settings**, save an API key for one provider (OpenRouter, Claude by Anthropic, OpenAI, Gemini by Google or DeepSeek; keys are encrypted with libsodium using your site's salts), or point "Other" at any OpenAI-compatible service, such as an open-source model on your own server (Ollama, vLLM, LM Studio) or an AI gateway, test it, turn the assistant on, then turn on the tutor and describe it: name, personality, instructions (the prompt) and greeting. It appears on every document by default, or only where you say: `[documento_acessivel id="123" tutor="yes"]` or `tutor="no"`.

Readers open the bubble, type or speak a question and get an answer based on the document, shown as it is written (streaming, when PHP has cURL). A first question almost identical to one already answered for the same document gets the saved answer instantly, without a new call (it can be turned off). Some rules cannot be changed: answer from the document and say when the answer is not there, ignore commands hidden in the document text, and reply in the reader's language. Every answer is paid with your key, so there are limits per reader per hour and per day for the whole site.

= Why did my large PDF fail with "504 Gateway Time-out" before? =

The web server (nginx usually waits 60 seconds) gave up on the upload page while PHP was still converting. Now the file is converted in a separate request and the page follows the progress, so the proxy limit no longer matters. PHP's own limits still apply: if PHP runs out of memory or time, the screen and the activity log say which limit was reached.

= Which languages does the plugin have? =

English (source), Brazilian Portuguese, Spanish, French, Simplified Chinese, Hindi, Russian and German. They follow the site language, or the user's language in the dashboard. Official translations from translate.wordpress.org, when available, take precedence over the bundled ones. The non-Portuguese translations were machine-assisted and are waiting for native reviewers: use the "Review translation" button in the settings, or translate at translate.wordpress.org, to help. More languages are welcome.

== Privacy ==

* **No tracking.** The plugin does not collect, store or send personal data about your visitors, does not set cookies and does not load scripts, styles, fonts or images from other sites.
* **External services (all optional, server-side only; visitors' browsers never contact them).**
  * WordPress.org — `[jimca_instalacoes]` requests `https://api.wordpress.org/plugins/info/1.2/` ([privacy policy](https://wordpress.org/about/privacy/)), cached for 12 hours. Only the plugin slug is sent.
  * AI providers — (1) when an administrator presses a provider's "Test connection" button: one fixed test sentence, with the key and the chosen model; (2) when the AI tutor is on and a reader asks a question: the question, the last turns of that reader's conversation (kept in the reader's browser for the session) and passages of the document. Only to the provider chosen in the settings. The reader's IP address is not sent; it is only used, hashed with the site's salt, to count questions per hour, and the count expires after an hour. Each provider has its own terms and may process data in other countries:
    * OpenRouter — `https://openrouter.ai/api/v1/chat/completions` ([terms](https://openrouter.ai/terms), [privacy policy](https://openrouter.ai/privacy)).
    * Anthropic (Claude) — `https://api.anthropic.com/v1/messages` ([terms](https://www.anthropic.com/legal/commercial-terms), [privacy policy](https://www.anthropic.com/legal/privacy)).
    * OpenAI — `https://api.openai.com/v1/chat/completions` ([terms](https://openai.com/policies/services-agreement/), [privacy policy](https://openai.com/policies/privacy-policy/)).
    * Google (Gemini) — `https://generativelanguage.googleapis.com/v1beta/openai/chat/completions` ([terms](https://ai.google.dev/gemini-api/terms), [privacy policy](https://policies.google.com/privacy)).
    * Other (OpenAI-compatible) — the address the administrator sets: an open-source model on your own server (Ollama, vLLM, LM Studio, LocalAI, llama.cpp, Hugging Face TGI) or an AI gateway (LiteLLM, Portkey, Cloudflare AI Gateway...). Its terms are those of whoever runs it; on your own server, the data does not leave it.
    * DeepSeek — `https://api.deepseek.com/chat/completions` ([terms](https://cdn.deepseek.com/policies/en-US/deepseek-open-platform-terms-of-service.html), [privacy policy](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html)).
* **Activity log.** The last 200 entries (file names, times, memory use, counts and errors) are kept in a site option for administrators, and can be cleared or exported. Document text and the AI keys are never recorded.
* **Suggested privacy-policy text** is added to Settings > Privacy > Policy Guide.
* **Browser storage.** The reading controls and the highlighter use the reader's own browser storage (`localStorage`) to remember text size, theme, contrast, voice, speed, whether the bar or the tutor is hidden, and highlighted passages, per document. The tutor conversation is kept in `sessionStorage` until the tab is closed. This data stays in the browser (the tutor conversation is sent with each new question, as described above).
* **Saved tutor answers.** To answer repeated questions instantly, answers may be kept per document, in post meta, with the weight of each word of the question (not the question text) and nothing that identifies the reader. They are discarded when the document or the tutor settings change, and can be turned off.
* **Voice questions** to the tutor use the browser's own speech recognition, when the browser has it. Some browsers (Chrome, Edge) send the audio to their vendor to turn it into text; the plugin only receives the text.
* **Text-to-speech** uses the browser's built-in Web Speech API. Depending on the browser and voice chosen, the browser itself may process speech on the vendor's servers; the plugin does not send the text anywhere.
* **Uploaded files** are processed on your server only.

== Screenshots ==

1. A converted document with the floating reading bar: menu, settings, text size, play/pause, speed, stop, parts and hide.
2. The same document on a phone, with the bar fitting seven controls at a 48px touch target.
3. The appearance menu open above the bar, following the Material Design 3 menu pattern.
4. The in-plugin Tutorial screen: what the plugin is, how to use it, version and authorship.

== Changelog ==

= 2.2.0 =
* New: redesigned floating reading bar: menu (search and contents), settings (voice and theme, with High contrast as a theme), text size, play/pause always in the center, speed that changes with each click, stop, parts and hide. Popovers open with a spring animation and respect reduced motion.
* New: long documents are split into parts (like an e-book reader). Only one part is loaded at a time, the next one is fetched in advance, and listening continues into the next part. Short documents can use `pages="yes"`.
* New: search in the document, by word or passage, ignoring case and accents. In documents with parts the search runs on the server over the whole book.
* New: selection bar next to the selected text, with "Share as image" (a card with the passage, the title and the page address) and "Download as text".
* New: the assistant is set up in one form with three steps (connect, choose the model, turn on) and is shown in every document once it is on (`tutor="no"` hides it on a page). Answers are short and avoid spoilers by default.
* New: quick prompts in the chat (a "=" button), editable under Settings > JIM > Advanced > Quick prompts; "Challenge me" and "Memory game" buttons; download the conversation as text.
* New: guided tour of the Settings screen (7 steps).
* New: conversion review in the editor sidebar and a "Review" button in the documents list; each review is recorded in the activity log.
* New: firewall-proof uploads: each file is sent with a neutral name, one per request, with progress per file. A 403 from the host becomes a readable notice.
* New: import queue limits shown and enforced: up to 20 files at a time, size per file, and the largest PDF the server memory can handle.
* Security: provider error messages in the log and in "Test connection" never contain the API key. Pages with the shortcode and the Jim screens send `frame-ancestors 'self'` and `X-Frame-Options: SAMEORIGIN` (filter `jimca_frame_ancestors`).
* Fix: the start of a conversion was logged twice when the file was sent with JavaScript.
* All new texts are translated into the seven bundled languages.

= 2.1.0 =
* New: "Also publish in" on the New Document screen. Choose a post type (posts, pages or a custom post type with a public page and the editor; field groups, templates and other internal types are not listed) and each imported file also becomes a post there, with the shortcode already inside, so you no longer copy and paste shortcodes one by one. The post address (slug) is the file name.
* New: choose the publication status of those posts (publish now, draft, pending, private or schedule, with an interval in hours between the files of a batch). Scheduled posts are events of [Action Scheduler](https://actionscheduler.org/): the post is created at the chosen time, and you can see, run or cancel the event under Tools > Scheduled Actions. The date is read by the site clock, which the form now shows.
* New: "Display mode" (Accessible reader, with reading bar and voice, or Standard blog) can now be chosen when you import, not only in the documents list.
* New: WordPress categories and tags now also classify the Jim documents (columns and filters in the documents list), and fields for the taxonomies of the chosen post type appear in the import form.
* New: "Published in" column in the documents list, linking to the post that shows each document.
* New: a guided tour of the New Document screen (9 steps), shown by itself after a fresh installation and available from a button on that screen and from the Tutorial tab. It is a non-modal dialog that takes focus at each step, works with the keyboard (Esc leaves it), outlines the control it explains and never animates.
* The Tutorial tab now explains publishing in posts, pages and custom post types, scheduling and the display modes.
* All new texts are translated into the seven bundled languages.

= 2.0.1 =
* Fix: the "Stop" button now really stops the reading; before, it kept speaking and went back to the start of the page (also fixed after Pause, a speed change, or pressing Listen again).
* Fix: "Listen" now starts at the passage you are on (selected text, focused heading or the first paragraph on screen) instead of jumping back to the top of the page.
* New: skip links at the top of each document (to the content, the reading controls and the contents list), hidden until they receive focus (WCAG 2.4.1 Bypass Blocks).
* New: "Conversion review" box on the document edit screen ("Did you find problems in the conversion? Click here"): checks for headings that are only bold text, text outside paragraphs, long text inside headings, empty paragraphs, `<br><br>` used as spacing and skipped heading levels, and fixes them in the editor.
* Fix: documents edited in the classic editor lost their paragraph tags and the reading bar found nothing to read; paragraphs are now restored when the document is shown.
* Fix: reading bar and menus no longer run off the screen on phones whose theme widens the page.

= 2.0.0 =
* New: English source strings with Brazilian Portuguese, Spanish, French, Simplified Chinese, Hindi, Russian and German translations, and a "Languages and translations" card with a "Review translation" button.
* New: activity log in the settings, live, with CSV export.
* New: API keys (encrypted) for OpenRouter, Claude (Anthropic), OpenAI, Gemini (Google) and DeepSeek, with a model field and a "Test connection" button per provider; the assistant itself comes later.
* New: JPEG 2000 images in PDFs are converted when PHP has the Imagick extension with JPEG 2000 support; the settings say whether the server supports it.
* Changed: default image limits raised to 100 images and 60 MB per document (installs that kept the old 40/30 defaults are moved to the new ones); the time and memory guards still stop image extraction before the server runs out.
* New: AI tutor for readers (name, personality, instructions, greeting; text and voice questions; per-shortcode `tutor="yes|no"`; hourly and daily limits).
* New: PDF and DOCX conversions run in the background with a progress bar. PDFs are converted in steps of at most 20 seconds that resume where they stopped, so hosts that end requests after 30 or 60 seconds (nginx, LiteSpeed) no longer break large books; memory and time failures, stuck steps and abandoned conversions are reported on screen and in the log.
* New: "Other (OpenAI-compatible)" AI provider, with your own address, model and optional key: open-source models on your server or AI gateways.
* Changed: tutor panel on Material 3 measures (icon top bar, suggestion chips, one-line field, filled send button); clearer texts; hiding the tutor now applies to that document only.
* New: tutor answers stream as they are written, repeated first questions are answered from saved answers, and the prompt is leaner (fewer passages and turns), for faster and cheaper answers.
* New: warning when a PDF's text is unreadable (fonts without a Unicode map).
* Fix: the document area no longer shows a focus outline around the whole text after "Skip to the document content".
* Fix: when images are left out, the notice is now a warning that says so, instead of "converted successfully".
* Fix: DOCX tables (for example exported from Google Docs) no longer turn every cell into a header, and cells no longer get a dark red background from Word's "auto" shading.
* Fix: CSS and JavaScript files carry their modification time in the URL, so style fixes reach browsers and caches without a version change.
* New: rewritten Tutorial screen and suggested privacy-policy text.
* New: per-document display mode (reader or blog), with a reading-time bar, listen and share buttons.
* New: images are saved to the Media Library, linked to the document and deleted with it; accessible lightbox.
* New: Markdown (.md) support and sending several TXT/MD files at once, with a ready-to-copy shortcode list.
* New: image count/size limits and time/memory guards, with the cause of each image left out.
* New: "Server requirements" table, automatic SEO tags, four-color highlighter, encrypted storage for a future AI key.
* New: specific messages for empty files, unsupported formats and content that doesn't match the extension; scanned PDFs and empty DOCX files are now refused instead of creating an empty document.
* Fixed: images in Microsoft Office PDFs with palette color spaces are now extracted.
* Removed: the image badge format of `[jimca_instalacoes]` (it loaded an image from a third-party site on visitors' browsers).
* Changed: the missing-dependencies notice now shows only to users who can activate plugins, on the Plugins and Jim screens, and can be dismissed.

= 1.0.0 =
* Initial release: PDF/DOCX/TXT conversion, accessible document shortcode with text-to-speech, reading themes (Light/Sepia/Dark), and WordPress.org install-count shortcode.
* Reading controls float at the bottom of the screen while the document is in view, with menus that open above each button; the reader's choices (size, theme, contrast, voice, speed, bar hidden) are remembered per document in their own browser.
* Progress feedback while a document is being converted, and readable messages instead of a raw error page when the server rejects an upload or lacks a required PHP extension.

== Upgrade Notice ==

= 2.2.0 =
New reading bar, long documents in parts with search, editable quick prompts for the assistant and safer uploads and error messages. If the AI is on with a saved key, the assistant now appears in every document; use `tutor="no"` to hide it on a page.

= 2.1.0 =
New "Also publish in" option: importing files can now create posts or pages (or custom post types) with the shortcode inside, schedule them with Action Scheduler, and choose the display mode at import. A guided tour explains it all. Nothing changes if you do not use it.

= 2.0.1 =
Adds a conversion review tool, and fixes documents that lost their paragraphs after being edited and the reading bar on phones.

= 2.0.0 =
Images are now stored in the Media Library (existing documents keep their old images). The `badge` format of `[jimca_instalacoes]` was removed.
