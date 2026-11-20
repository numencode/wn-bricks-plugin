# Bricks Plugin

The **Bricks Plugin** is a translatable, block-based content builder for [Winter CMS](https://wintercms.com) static
pages. Editors compose a page from a list of **bricks** (headings, text, images, galleries, columns, components, …):
they add, reorder, duplicate, hide and delete them. Developers define brick types as **YAML fields + Twig markup** in
plugins or themes, and themes can override every brick's markup.

[![Tests](https://img.shields.io/github/actions/workflow/status/numencode/wn-bricks-plugin/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/numencode/wn-bricks-plugin/actions)
[![License](https://img.shields.io/github/license/numencode/wn-bricks-plugin?label=open%20source&style=flat-square&color=0099FF)](https://github.com/numencode/wn-bricks-plugin/blob/main/LICENSE.md)

---

## Features

- **Bricks on static pages** – one tag in a layout turns the page's content into a list of bricks.
- **A base set of 11 bricks** – heading, text, image, gallery, columns, button, quote, video, divider, spacer and HTML,
  with plain, semantic markup.
- **Your own brick types** – a YAML file with standard backend form fields plus a Twig file, in a plugin or a theme.
- **Theme overrides** – a theme can replace the markup (`partials/bricks/<code>.htm`) or the fields of any brick type.
- **Bricks on any model** – a `type: bricks` form field stores bricks in a model's attribute, e.g. on blog posts.
- **Component bricks** – CMS components become bricks, with fields generated from their properties. Every Winter.Pages
  snippet component (e.g. [NumenCode.Widgets](https://github.com/numencode/wn-widgets-plugin)) is a brick
  automatically.
- **Translations** – with [Winter.Translate](https://github.com/wintercms/wn-translate-plugin), each language has its own
  list of bricks, with "copy from" (and machine translation of the marked fields).
- **A comfortable editor** – collapsed bricks show their type and a summary of their content; "Expand all" /
  "Collapse all"; duplicate, hide, and copy and paste between pages and languages; an anchor and a CSS class on every brick; the "Add brick" list in
  sections (the theme's own bricks first, then the general ones, then each plugin's); "Edit …" links to where a component brick's content is managed; the page preview as its own tab.
- **Safe editing** – brick types can require permissions (checked on the server, too), and bricks whose type no
  longer exists keep their content.
- **Developer commands** – `create:brick` scaffolds a brick type or copies one into the theme, `bricks:list` shows where
  each type comes from and what it overrides, `bricks:validate` checks definitions and markup in CI,
  `bricks:convert-pages` turns existing pages (rich editor content and snippets) into bricks.
- English and Slovenian backend translations.

## Requirements

- PHP 8.2 or newer
- [Winter CMS](https://wintercms.com) 1.2 (tested with 1.2.15)
- [NumenCode.Fundamentals](https://github.com/numencode/wn-fundamentals-plugin) 2.1 or newer (installed automatically)
- [Winter.Pages](https://github.com/wintercms/wn-pages-plugin) – bricks live on static pages
- Optional: [Winter.Translate](https://github.com/wintercms/wn-translate-plugin) for translations

## Installation

```bash
composer require numencode/wn-bricks-plugin
php artisan winter:up
```

## Quick start

Add a bricks field to a layout and render it:

```twig
description = "Page built from bricks"

[staticPage]
useContent = 0
==
{variable type="bricks" name="bricks" label="Bricks" tab="Content"}{/variable}
<!DOCTYPE html>
<html>
    <body>
        <main>
            {{ renderBricks() }}
        </main>
    </body>
</html>
```

- Winter.Pages only offers layouts that contain the `[staticPage]` component.
- `useContent = 0` removes the page's rich-editor "Content" field, which a bricks layout usually doesn't need. Leave it
  out to combine the rich-editor content with bricks.

Pages that still have rich-editor content can fall back to it while they have no bricks:

```twig
{% set bricksHtml = renderBricks() %}
{% if bricksHtml|trim %}{{ bricksHtml|raw }}{% else %}{% page %}{% endif %}
```

### The layout tag

```twig
{variable type="bricks" name="bricks" label="Bricks" tab="Content" allow="text,image,gallery" ignore="html"}{/variable}
```

| Option | Description |
|---|---|
| `name` | The field name; the bricks are stored in the page's `viewBag[<name>]`. A layout can have several bricks fields (e.g. `bricks` and `aside`). |
| `label`, `tab`, `placement` | As for other layout variables. |
| `prompt` | The text of the "Add brick" button. |
| `allow` | Only these brick types are offered, in this order (comma separated codes). |
| `ignore` | These brick types are not offered. |

Bricks of types a field doesn't offer (because of `allow`/`ignore` or permissions) keep their content: they can't be
added, but existing ones are shown and saved unchanged.

## Editing bricks

- **Adding:** "Add brick" lists the brick types: the base set and the theme's types first, then each plugin's types
  under its name (e.g. "NumenCode Widgets"). The list has a search box.
- **Collapsed bricks** show the type and a summary of their content, e.g. "**Text:** Our story began in…". "Expand all"
  and "Collapse all" open or close every brick.
- **Actions on each brick:** copy, duplicate (the copy appears right after it), hide on the website (the brick is
  dimmed and marked "Hidden"; it is kept but not rendered), collapse and delete.
- **Copy and paste:** a copied brick is kept in the browser, and "Paste brick" (next to "Expand all") adds it at
  the end of any bricks field that offers its type – on another page, in another language or in another browser tab.
  The × next to it forgets the copied brick.
- **Advanced:** every brick has an optional anchor (for links like `#contact`) and CSS class.
- **"Edit …" links:** a component brick whose content is managed elsewhere links there, e.g. a NumenCode.Widgets brick
  opens its group's form in a new tab.
- **Preview link:** the crosshairs button of a brick opens Winter.Pages' preview and scrolls to the brick, which blinks
  there. Clicking a brick's bar also makes it the one shown when the preview is opened. Edits and reordering refresh
  the preview.
- **Preview tab:** with the bricks in the main tabs (NumenCode.Fundamentals' `pages_viewbag_primary_tabs`, on by default),
  Winter.Pages' preview becomes a full-height tab, and the tabs are ordered Content, Preview, Meta, Settings.
- **Unknown bricks:** a brick whose type no longer exists (e.g. a theme definition was removed) is shown as "Unknown
  brick" with a notice. Saving the page keeps its content unchanged; it isn't rendered. Move or delete it.

## Base set

| Brick | Code | Fields |
|---|---|---|
| Heading | `heading` | `text`, `level` (h2, h3, h4) |
| Text | `text` | `content` (rich editor) |
| Image | `image` | `image`, `alt`, `caption`, `width` (content, wide, full) |
| Gallery | `gallery` | `images` (image, alt, caption), `columns` (2, 3, 4) |
| Columns | `columns` | `columns` (2, 3), `column_1` … `column_3` (rich editor) |
| Button | `button` | `text`, `url`, `style` (primary, secondary, link), `new_window` |
| Quote | `quote` | `quote`, `author`, `source` |
| Video | `video` | `url` (YouTube, embedded from youtube-nocookie.com, or Vimeo), `title` |
| Divider | `divider` | – |
| Spacer | `spacer` | `size` (small, medium, large) |
| HTML | `html` | `html` (code editor); needs the permission `numencode.bricks.use_html` |

The markup is plain HTML with BEM-style classes and no CSS framework: `brick brick--<code>`, elements `brick__*` and
modifiers such as `brick--image-wide`. Style it in your theme, or override the markup.

## Defining brick types

A brick type is a YAML definition (`<code>.yaml`) and Twig markup (`<code>.htm`). The code (the file name) may contain
`a-z`, `0-9`, `-` and `_` and must start with a letter.

```yaml
# callout.yaml
name: Callout                       # plain text or a lang key
description: A highlighted note
icon: icon-bullhorn
titleFrom: text                     # the field shown on a collapsed brick (default: the first filled field)
fields:                             # standard backend form fields
    text:
        label: Text
        type: textarea
        translatable: true          # machine-translated with Winter.Translate's "copy from"
    style:
        label: Style
        type: dropdown
        options:
            info: Info
            warning: Warning
```

```twig
{# callout.htm #}
<aside class="callout callout--{{ style }}{% if brick.class %} {{ brick.class }}{% endif %}"{% if brick.anchor %} id="{{ brick.anchor }}"{% endif %}>
    {{ text|nl2br }}
</aside>
```

| Key | Description |
|---|---|
| `name` | Required. The name in the editor. |
| `description` | Shown in the "Add brick" list. |
| `icon` | An icon class (default `icon-cube`). |
| `fields` | Backend form fields. A type without fields (e.g. a divider) only needs markup. |
| `titleFrom` | The field summarised on a collapsed brick. |
| `component` | Makes it a component brick (see below). |
| `permissions` | A permission code or a list; only backend users with one of them may add or change these bricks. |
| `editUrl` | A backend URL where the brick's content is edited, with `{field}` placeholders, e.g. `acme/shop/products/update/{product}`. |

The field names `_group`, `_hidden`, `_anchor`, `_class`, `_id` and `brick` are reserved.

**Markup variables:** every field by its name, the page's variables, and `brick`:

| Variable | Description |
|---|---|
| `brick.code` | The brick type. |
| `brick.index` | The brick's position in its field (from 0). |
| `brick.field` | The bricks field's name (e.g. `bricks`). |
| `brick.id` | The brick's stable id (a short random string that stays the same when the brick is moved or edited). |
| `brick.anchor` | The anchor, as a slug ("Our team" → `our-team`), or empty. |
| `brick.class` | The CSS classes (letters, digits, `-` and `_`), or empty. |

All CMS filters and functions work (`|media`, `|resize`, `|page`, `|_`, …).

### In a plugin

```php
public function registerBricks(): array
{
    return [
        'callout' => '$/acme/shop/bricks/callout',   // callout.yaml + callout.htm, without the extension
    ];
}
```

Brick types can also be registered from code with
`BrickRegistry::instance()->registerCallback(fn ($registry) => $registry->registerBricks([...]))`.

### In a theme

- `themes/<theme>/bricks/<code>.yaml` – a new brick type, or the fields of an existing one (the definition replaces
  it). The theme's new types come first in the "Add brick" list, under the theme's name; a replaced type stays in
  its section.
- `themes/<theme>/partials/bricks/<code>.htm` – the markup of a brick type, plugin types included. It's a regular CMS
  partial.

Later definitions replace earlier ones with the same code: snippet components, the base set, other plugins, callbacks,
the theme.

## Console commands

### `create:brick` – scaffold a brick type

```bash
php artisan create:brick call-to-action                       # in the active theme
php artisan create:brick call-to-action --plugin=Acme.Shop    # in a plugin
php artisan create:brick image --override                     # copy a brick type into the theme, to change it there
php artisan create:brick image --markup                       # copy only its markup
```

A new type gets a definition with a title and a text field and matching markup: `bricks/<code>.yaml` +
`partials/bricks/<code>.htm` in a theme (in the "Add brick" list right away), or `bricks/<code>.yaml` + `.htm` in a
plugin (the command prints the line for `registerBricks()`). `--override` copies the definition and markup of the brick
type in use into the theme; `--markup` only the markup. A component brick from a snippet gets a definition with its
component, to override the generated fields.

Options: `--theme=` (default: the active theme), `--name=`, `--icon=`, `--force` (overwrite existing files).

### `bricks:list` – the brick types and their overrides

```bash
php artisan bricks:list [--theme=demo] [--overrides]
```

Lists every brick type with where it comes from (a plugin, `theme <name>`, a snippet or a callback), its definition and
markup files, and what it replaces – an earlier definition with the same code, or only its markup (a theme partial).
`--overrides` shows only the types that replace something.

### `bricks:validate` – check the definitions (for CI)

```bash
php artisan bricks:validate [--theme=demo] [--theme-only] [--strict]
```

Reads every definition separately (also the replaced ones), so one broken file doesn't hide the others, and exits with 1
when there are errors – add it to your CI pipeline or a deploy check.

- **Errors** (they break the editor or the website): invalid definitions, unknown field types (also in nested forms),
  Twig syntax errors and unknown filters, functions or tags in the markup, missing or unregistered components.
- **Warnings:** lang keys that don't exist, permissions that aren't registered, a component brick's field that isn't one
  of its properties or a `titleFrom` that isn't a field, theme partials that no brick type uses (partials starting
  with `_` are helpers and skipped) or that a component brick ignores.

`--theme-only` reports only problems in the theme's files; `--strict` fails on warnings too.

### `bricks:convert-pages` – turn existing pages into bricks

```bash
php artisan bricks:convert-pages about /contact --dry-run   # by file name or URL; show the bricks, save nothing
php artisan bricks:convert-pages --all                      # all static pages, every language
```

Converts the content of static pages (Winter.Pages' rich editor) into bricks, in the default language's file and in
every translation that has its own content. The top-level parts of the content become:

| Content | Brick |
|---|---|
| A snippet (`<figure data-snippet … data-property-…>`) | Its component brick, with the properties as values |
| `<h2>`–`<h4>` with plain text (an `id` becomes the anchor) | Heading |
| A paragraph or `<figure>` with only an image from the media library (and a caption) | Image |
| `<hr>` | Divider |
| Everything else (paragraphs, lists, tables, headings with links, …) | Text, one per run between the bricks above |

Empty paragraphs between them are dropped. `--no-split` makes only component and text bricks. Snippets inside other
HTML (e.g. a table) and snippets without a brick type stay in a text brick, with a warning. Layout tables are best
rebuilt by hand, e.g. as Columns bricks.

The page's **old content is kept**, so nothing is lost and the page still has it if the bricks are removed. Pages that
already have bricks are skipped unless `--force` is given (which replaces their bricks). The command warns when a
page's layout has no bricks field yet.

Options: `--all`, `--locale=` (repeatable; `default` or a language code), `--field=` (default `bricks`), `--theme=`,
`--no-split`, `--dry-run`, `--force`.

### `bricks:clear-markup` – remove the old content

```bash
php artisan bricks:clear-markup --all --dry-run
php artisan bricks:clear-markup --all
```

Once you've checked the converted pages in the browser, this removes their old content. Only files with their own
bricks are cleared, and only when the page's layout has the bricks field (otherwise the layout would still show the
old content). It asks before removing (`--force` doesn't). Same options as `bricks:convert-pages`, without `--no-split`.

## Component bricks

A component brick runs a CMS component with the brick's values as its properties. Its fields are generated from the
component's properties (string → text, text → textarea, dropdown, checkbox, set → checkbox list; dropdown options from
the component's `get<Property>Options()`). The component runs on the page like a Winter.Pages snippet, so `onRun()` and
AJAX handlers work.

- **Snippets:** every component a plugin registers as a Winter.Pages snippet is a brick automatically, with the code
  `snippet-<snippet code>` (e.g. `snippet-gallery`). Limit or switch this off in `config/numencode/bricks/config.php`:

  ```php
  return [
      'snippets' => true,                                              // all snippet components (default)
      // 'snippets' => [\NumenCode\Widgets\Components\Gallery::class],   // only these
      // 'snippets' => false,                                          // none
  ];
  ```

- **Explicitly,** with the `component` key; `fields` then changes the generated fields:

  ```yaml
  name: Highlights
  icon: icon-star
  component: NumenCode\Widgets\Components\Highlights
  fields:
      layout:
          hidden: true
  ```

A component can declare its brick's icon (`'icon' => 'icon-star'` in `componentDetails()`; otherwise its plugin's
icon) and where its content is edited (a static `bricksEditUrl(): ?string` returning a URL pattern like `editUrl`, or
`null` for no link, e.g. without permission).

A component brick's markup is the component's own; with an anchor or CSS class it is wrapped in a `<div>` with them.

## Rendering

| Twig | Description |
|---|---|
| `{{ renderBricks() }}` | The current static page's `bricks` field. |
| `{{ renderBricks('aside') }}` | Another bricks field of the page. |
| `{{ renderBricks(list, 'name') }}` | A list of bricks from elsewhere, e.g. a model's: `renderBricks(post.bricks, 'post')`. The name keeps its component bricks apart from the page's. |
| `{{ renderBrick(brick, index, field) }}` | One brick, e.g. in `{% for brick in bricks %}{{ renderBrick(brick, loop.index0) }}{% endfor %}`. |
| `{{ url\|bricksVideoUrl }}` | The embed URL of a YouTube (youtube-nocookie.com) or Vimeo video, or empty. |

Hidden bricks and bricks of unknown types are not rendered (an unknown type is logged in debug mode).

Component bricks of a static page's fields are added to the page before it runs, so their AJAX handlers work. Component
bricks of other lists (e.g. a model's) are created while rendering: their `onRun()` runs, but their AJAX handlers can't
be reached.

## Bricks on other models

Any model can have bricks fields, e.g. blog posts ([NumenCode.BlogExtension](https://github.com/numencode/wn-blogextension-plugin)
adds them to Winter.Blog posts). Store the bricks in a jsonable text attribute and use `type: bricks` in the form:

```php
class Project extends Model
{
    public $implement = ['@Winter.Translate.Behaviors.TranslatableModel'];

    public $translatable = ['title', 'bricks'];   // one list of bricks per language (optional)

    protected $jsonable = ['bricks'];              // a (medium)text column
}
```

```yaml
# fields.yaml
tabs:
    fields:
        bricks:
            tab: Content
            type: bricks
            allow: text, image, gallery   # optional, like the layout tag
```

```twig
{{ renderBricks(project.bricks, 'project') }}
```

The field offers the active theme's brick types and works like on static pages: "Add brick", the editor's actions,
permissions checked on save, empty options dropped, bricks of missing types kept. With Winter.Translate, list the
attribute in `$translatable` to get one list per language with "copy from"; Winter.Pages' preview tab is only on
static pages.

## Translations

With Winter.Translate, each language has **its own list of bricks** (the field becomes Translate's multilingual
repeater):

1. Edit the page in the default language.
2. Switch the bricks field to another language. While its list is empty, a hint says so: the website shows the
   default language's bricks there.
3. "Copy the … bricks" (or "copy from" in the language menu) copies the list. With a translation provider configured,
   the fields marked `translatable: true` are machine-translated.
4. Edit and save.

The lists are independent after the copy: adding, moving or removing a brick in one language doesn't change the other
languages. A component brick's own content (e.g. a widget group) is translated where it is managed.

## Permissions

- `numencode.bricks.use_html` – add and edit HTML bricks.
- Any brick type can require permissions (`permissions` in its definition). Users without them don't see the type in
  "Add brick", and its existing bricks are read-only (they can still be moved, hidden, duplicated and deleted).
- The server checks it on save, in every language: a request that adds or changes such a brick without the permission
  is rejected.

## Data format

Bricks are stored in the page's `viewBag` (the page file in `content/static-pages`, the other languages in
`content/static-pages-<locale>`), one entry per brick, with the type in `_group` and a stable id in `_id` (assigned when the page is
saved; a duplicated or pasted brick gets a new one):

```ini
[viewBag]
title = "About"
url = "/about"
layout = "bricks"
bricks[0][text] = "Our story"
bricks[0][level] = "h2"
bricks[0][_group] = "heading"
bricks[0][_id] = "k3v9q0x2ma"
bricks[1][content] = "<p>It began in a small workshop…</p>"
bricks[1][_anchor] = "story"
bricks[1][_group] = "text"
bricks[1][_id] = "p8d1w5rj4c"
bricks[2][size] = "large"
bricks[2][_hidden] = 1
bricks[2][_group] = "spacer"
bricks[2][_id] = "z0t7h2nb6e"
```

The pages stay theme files: deploy them as before. Bricks need no cache of their own: Winter caches the parsed YAML
definitions by their modification time.

## Roadmap

Planned before the first release (1.0.0):

- **Shared brick structure with per-field translations** (opt-in per field, e.g.
  `{variable type="bricks" translation="fields"}`): one list of bricks, arranged in the default language; other
  languages translate the text fields, and an untranslated field shows the default language's value.
- **Bricks inside bricks:** a bricks field inside a brick type. The Columns brick will hold a list of bricks per
  column.

## Changelog

All notable changes are documented in the [CHANGELOG](CHANGELOG.md).

## Contributing

Please refer to the [CONTRIBUTING](CONTRIBUTING.md) guide for details on contributing to this project.

## Security

If you discover a security issue, please email <info@numencode.com> instead of using the issue tracker.

## Author

The **NumenCode.Bricks** plugin is created and maintained by [Blaz Orazem](https://orazem.si/).
More NumenCode plugins: [numencode.com](https://www.numencode.com).

For inquiries, contact: <info@numencode.com>

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
