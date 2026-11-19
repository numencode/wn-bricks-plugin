# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The first release.

### Added

- **Bricks fields on static pages:** `{variable type="bricks" name="…"}` in a layout turns into a list of bricks in the
  Winter.Pages editor, with `allow` / `ignore` to choose the offered brick types. Several bricks fields per layout.
- **Brick types** as YAML (name, description, icon, backend form fields, `titleFrom`, `permissions`, `editUrl`) plus Twig
  markup, from plugins (`registerBricks()`, `BrickRegistry::registerCallback()`) and themes (`bricks/<code>.yaml`).
  Themes override any type's markup with `partials/bricks/<code>.htm`.
- **Base set:** heading, text, image, gallery, columns, button, quote, video (YouTube, Vimeo), divider, spacer and HTML,
  with plain BEM-style markup and English and Slovenian labels.
- **Component bricks:** a component's properties become the brick's fields, and the component runs on the page
  (`onRun()`, AJAX handlers). Winter.Pages snippet components are bricks automatically (config `snippets`); components
  can declare their brick's icon (`componentDetails()['icon']`) and where their content is edited
  (`bricksEditUrl()`).
- **Rendering:** the Twig functions `renderBricks()` and `renderBrick()`, the filter `bricksVideoUrl`, and the `brick`
  variable (code, index, field, anchor, class) in the markup.
- **Translations** with Winter.Translate: one list of bricks per language, "copy from" another language (with machine
  translation of the fields marked `translatable: true`), and a hint above an empty list in another language, which
  shows the default language's bricks on the website, with a button to copy them.
- **Editor:**
  - collapsed bricks show their type and a summary of their content
  - "Expand all" / "Collapse all"
  - the "Add brick" list in sections: the theme's own brick types first (under the theme's name), then the general
    ones, then each plugin's (e.g. "NumenCode Widgets"), with the components' icons
  - duplicate a brick
  - copy and paste bricks between pages and languages (kept in the browser's local storage)
  - hide a brick on the website without deleting it
  - an optional anchor and CSS class on every brick ("Advanced")
  - "Edit …" links where a component brick's content is managed elsewhere
  - Winter.Pages' preview as a full-height tab, and the tabs ordered Content, Preview, Meta, Settings
  - a button that opens the preview at a brick (it blinks there); reordering refreshes the preview
- **Permissions:** `numencode.bricks.use_html` for HTML bricks, and `permissions` on any brick type. Users without them
  can't add these bricks and see existing ones read-only; the server rejects saves that add or change them, in every
  language.
- **Bricks of unknown types** (e.g. a removed theme definition) keep their content when the page is saved; they are
  shown as "Unknown brick" and not rendered.
- **Console commands:**
  - `create:brick` scaffolds a brick type in a theme or a plugin, or copies an existing type (`--override`) or only its
    markup (`--markup`) into the theme
  - `bricks:list` lists the brick types, where each comes from and what it overrides
  - `bricks:validate` checks every definition and markup file (field types, Twig, components, lang keys,
    permissions, unused partials) and exits with 1 on errors, for CI (`--theme-only`, `--strict`)
  - `bricks:convert-pages` turns the content of static pages into bricks in every language: snippets into component
    bricks, headings, images and dividers into their bricks, the rest into text bricks; the old content is kept
  - `bricks:clear-markup` removes the old content of converted pages once they are checked
- `BrickRegistry::sources()` lists every definition of each brick code, also the replaced ones.

[Unreleased]: https://github.com/numencode/wn-bricks-plugin/commits/main
