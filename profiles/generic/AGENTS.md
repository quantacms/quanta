# AGENTS.md — Quanta site

This directory is a **Quanta CMS site**: content, templates and site modules for one host. The
Quanta engine is two levels up (this site lives at `sites/<host>/` inside a Quanta checkout), and
`<host>` below is this directory's name. The file was copied here by `doctor setup` from Quanta's
`profiles/generic/`. Extend the "This site" section at the end with your own conventions.

> This file sits inside the web root. Never put secrets, credentials or private notes in it.

## How a Quanta site works

- **Content is folders.** Every node is a directory holding `data.json` (language-neutral) and/or
  `data_<lang>.json` (one per language), plus attached files and templates. Subfolders are child
  nodes.
- **The URL is the folder name.** `/anything/cat/` renders the node `cat` wherever it lives, so
  **node names must be unique across the whole site**: lowercase letters, digits and `-`.
- **Names starting with `_`** are system or hidden nodes (components, `_system`, `db/_users`).
  They aren't listed or published.
- **Markup is Qtags:** `[TAG|attr=value|flag:target]`; `{TAG…}` nests inside another tag's
  attribute.
- **Every page renders inside `index.html`.** `[MAIN]` shows the current node through its
  template, and `[HEADER|…:_header]` renders the `_header` component node.

## Layout

| Path | Purpose |
|---|---|
| `index.html` | Page shell (head, header, `[MAIN]`, footer, CSS/JS bundles) |
| `pages/` | Site pages. `pages/tpl^.html` lays out every page below it |
| `_components/` | Layout nodes (`_header`, `_footer`, `_logo`, …), each rendered through its `tpl.html` |
| `_example_data/` | Demo content from the profile (safe to delete) |
| `_tpl/` | Template overrides by node name: `<node>_tpl.html`, `<node>_tpl-.html`, `<node>_tpl^.html` |
| `_modules/<module>/` | This site's PHP modules (hooks, classes, Qtags, templates, assets) |
| `assets/`, `files/` | Static files served at `/assets/…` and `/files/…` |
| `_system/` | System pages such as `403` and `404` |
| `db/` | `_users`, `_roles`, `_languages`, `_translations`, `_statuses` (system nodes) |
| `jobs/` | Job queue nodes (`_jobs_todo`, `_jobs_done`, `_jobs_unknown`) |
| `.env` | `KEY=VALUE` configuration and secrets. Must stay out of git (add it to `.gitignore`) |
| `_profile_info/` | Profile metadata, unused at runtime |

## Templates

A node renders through the first template found: its own `tpl.html`, then
`_tpl/<node>_tpl.html`, then the nearest ancestor's `tpl-.html` (children), `tpl--.html`
(grandchildren), … up to five dashes, then the nearest `tpl^.html` (all descendants), and finally
its `body`. Templates are HTML + Qtags.

```html
<!-- pages/tpl^.html: every page -->
<h1>[TITLE]</h1>
<div class="teaser">[TEASER]</div>
[BODY]
[LIST_NODES|tpl=title_image_linked:[NODEITEM]]   <!-- children of the current node -->
```

## Qtag cheat sheet

```
[TITLE] [BODY] [TEASER] [AUTHOR]              fields of the current node (or [TITLE:<node>])
[ATTRIBUTE|name=price]                        a custom key from data.json
[LINK:<node>]   [LINK|title=Home:home]        links to nodes
[CONTENT:<node>]   [RENDER:<node>]            embed another node through its template
[LIST_NODES|tpl=dir:<node>]                   list children (templates in Quanta's src/modules/list/tpl/)
[MENU|links={LINK:home}---{LINK:about}]       menu (items separated by ---)
[IMG:<file>]   [THUMBNAIL|640x480:<node>]     an attached image / a node's thumbnail
[TEXT|tag=read-more:Read more]                translatable string
[GRID|class=…:…]  and  grid=<classes> on any tag   CSS-grid layout
[EDIT] [ADD] [DELETE]                         editing links (shown only to users allowed to act)
[FORM|name=contact:…] + [INPUT|type=email|name=email:contact]   forms
filter=user_role@admin                        on any tag: render only when the criteria match
```

Rules: tag names are `UPPER_SNAKE`, attribute values can't contain `:`, and pages are rendered
with their newlines removed. Avoid `//` comments in inline `<script>`, and write `[` `]` in text
as HTML entities if they could look like a tag.

## Common tasks

- **New page:** create `pages/<name>/data_<lang>.json` with `title`, `teaser`, `body`, `status`
  (`node-status-published`), or add it through the site's edit UI. If the URL still says "not
  found", clear the cache (below).
- **Custom field:** add a key to the node's JSON and show it with `[ATTRIBUTE|name=<key>]`.
- **Permissions:** a `permissions` object in `data.json` (`node_view`, `node_edit`, `node_add`,
  `node_delete`; values are roles such as `anonymous`, `logged`, `admin`, or `author` / `inherit`).
  Children inherit it.
- **Site styling:** edit `assets/css/style.css` (already loaded by `index.html`).
- **PHP behaviour:** a module in `_modules/<module>/` with `hooks/<module>.hook.inc`
  (`namespace Quanta\Common;`, functions `<module>_<hook>(Environment $env, array $vars)`).
  Qtags go in `_modules/<module>/classes/Qtags/<Class>.qtag.php`.
- **Secrets / API keys:** put them in `.env` and read them with `$env->getData('KEY')`.

## Commands

Run from the Quanta root (`cd ../..`):

```sh
php doctor <host> check          # after adding classes/Qtags or changing module CSS/JS
php doctor <host> class_map      # after adding or renaming a PHP class only
php doctor <host> clear_cache    # a new or moved node 404s, or thumbnails are stale
```

## Don'ts

- Don't edit the engine (`../../src/`) from a site: put changes in `_modules/`.
- Don't name a site module like a core module unless you mean to replace the core one entirely.
- Don't edit generated files in `../../static/tmp/<host>/` (bundles, class map, caches).
- Don't create two nodes with the same folder name anywhere in the site.

## Engine reference

Read these (in the Quanta checkout) when a task goes beyond the above:

- `../../AGENTS.md`: engine overview, rules and commands
- `../../docs/reference/templates-and-qtags.md`: template resolution, Qtag rules, the full tag catalog
- `../../docs/reference/content-model.md`: nodes, names, permissions, users, languages
- `../../docs/reference/modules.md`: forms, actions, API, jobs, mail, assets, doctor
- `../../docs/reference/recipes.md`: step-by-step checklists (new Qtag, form, API endpoint, …)

## This site

<!-- Add this site's own conventions here: content structure, custom modules, deployment notes
     that are safe to publish, and team rules. Keep secrets in .env, never in this file. -->
