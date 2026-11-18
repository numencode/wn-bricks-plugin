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
- **Component bricks** – CMS components become bricks, with fields generated from their properties. Every Winter.Pages
  snippet component (e.g. [NumenCode.Widgets](https://github.com/numencode/wn-widgets-plugin)) is a brick
  automatically.
- **Translations** – with [Winter.Translate](https://github.com/wintercms/wn-translate-plugin), each language has its own
  list of bricks, with "copy from" (and machine translation of the marked fields).
- **A comfortable editor** – collapsed bricks show their type and a summary of their content; "Expand all" /
  "Collapse all"; duplicate and hide buttons; an anchor and a CSS class on every brick; the "Add brick" list grouped
  by plugin; "Edit …" links to where a component brick's content is managed; the page preview as its own tab.
- **Safe editing** – brick types can require permissions (checked on the server, too), and bricks whose type no
  longer exists keep their content.
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
- **Actions on each brick:** duplicate (the copy appears right after it), hide on the website (the brick is dimmed and
  marked "Hidden"; it is kept but not rendered), collapse and delete.
- **Advanced:** every brick has an optional anchor (for links like `#contact`) and CSS class.
- **"Edit …" links:** a component brick whose content is managed elsewhere links there, e.g. a NumenCode.Widgets brick
  opens its group's form in a new tab.
- **Preview:** with the bricks in the main tabs (NumenCode.Fundamentals' `pages_viewbag_primary_tabs`, on by default),
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

The field names `_group`, `_hidden`, `_anchor`, `_class` and `brick` are reserved.

**Markup variables:** every field by its name, the page's variables, and `brick`:

| Variable | Description |
|---|---|
| `brick.code` | The brick type. |
| `brick.index` | The brick's position in its field (from 0). |
| `brick.field` | The bricks field's name (e.g. `bricks`). |
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
  it).
- `themes/<theme>/partials/bricks/<code>.htm` – the markup of a brick type, plugin types included. It's a regular CMS
  partial.

Later definitions replace earlier ones with the same code: snippet components, the base set, other plugins, callbacks,
the theme.

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
| `{{ renderBricks(list) }}` | A list of bricks (no component bricks; they need to be on the page). |
| `{{ renderBrick(brick, index, field) }}` | One brick, e.g. in `{% for brick in bricks %}{{ renderBrick(brick, loop.index0) }}{% endfor %}`. |
| `{{ url\|bricksVideoUrl }}` | The embed URL of a YouTube (youtube-nocookie.com) or Vimeo video, or empty. |

Hidden bricks and bricks of unknown types are not rendered (an unknown type is logged in debug mode).

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
`content/static-pages-<locale>`), one entry per brick, with the type in `_group`:

```ini
[viewBag]
title = "About"
url = "/about"
layout = "bricks"
bricks[0][text] = "Our story"
bricks[0][level] = "h2"
bricks[0][_group] = "heading"
bricks[1][content] = "<p>It began in a small workshop…</p>"
bricks[1][_anchor] = "story"
bricks[1][_group] = "text"
bricks[2][size] = "large"
bricks[2][_hidden] = 1
bricks[2][_group] = "spacer"
```

The pages stay theme files: deploy them as before. Bricks need no cache of their own: Winter caches the parsed YAML
definitions by their modification time.

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
