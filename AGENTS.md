# AGENTS.md — Quanta CMS

Instructions for AI coding agents (Codex, Claude Code, Copilot, Cursor, Gemini CLI, …) and for
anyone new to the codebase. This file is loaded into every agent session, so it stays short: the
details live in the documents under [Read more](#read-more). Open them when a task touches that area.

## What Quanta is

A PHP 8.5 CMS and framework with no SQL database. Every piece of content is a **node**: a
directory that holds a `data.json` (plus `data_<lang>.json` translations, attached files and
templates). Pages are written in **Qtags** (`[TAG|attr=value:target]`), a bracket markup that PHP
classes render. Behaviour is extended by **modules** that implement **hooks**. **qdb** is an
optional Rust PHP extension that indexes the node tree in shared memory; Quanta behaves the same
without it.

## Repository map

| Path | What it is |
|---|---|
| `src/boot.php` | Front controller: every non-static request and every `doctor` run goes through it |
| `src/autoload.php` | Class-map autoloader for `Quanta\Common\*` / `Quanta\Qtags\*`, then Composer |
| `src/modules/<module>/` | Core modules: `hooks/<module>.hook.inc`, `classes/Common/`, `classes/Qtags/`, `tpl/`, `assets/` |
| `profiles/generic/` | Install profile, copied into a new site by `doctor setup` |
| `qdb/` | Rust extension `quanta_db.so`, daemon `qdbd`, monitor `qdbstat` — see `qdb/AGENTS.md` |
| `Dockerfile`, `docker/` | Base image (nginx + php-fpm + qdb) — see `docker/AGENTS.md` |
| `.htaccess` | Apache rules for native installs (`docker/nginx/quanta.conf` is the nginx equivalent; `quanta-nginx.conf` is an old sample) |
| `docs/site/` | Source of the static docs site; the files listed in `docs/.build-manifest` are generated from it |
| `docs/reference/` | Hand-written engine reference (not part of the docs site build) |
| `tests/` | `logger_test.php` only; the real suites live in `qdb/tests/` and `docker/tests/` |
| `doctor` | CLI entry point: `php doctor <host> <command>` |

Not in git: `sites/` (each site is its own repository), `vendor/`, `static/tmp/` (generated per
site), `.env`, `node_modules/`.

## Commands

Run from the repository root. `<host>` is a directory name under `sites/`.

```sh
composer install                  # PHP dependencies; also points git at .githooks/
php doctor <host> setup           # create a site from a profile (interactive; --profile=generic)
php doctor <host> check           # rebuild class map, reindex + repair node links, rebuild CSS/JS bundles
php doctor <host> class_map       # rebuild the class map only
php doctor <host> clear_cache     # drop cached node paths and thumbnails
php -l <file>                     # syntax check
vendor/bin/phpcs <file>           # lint (.phpcs.xml); see "Code style" before acting on it
php tests/logger_test.php         # logger unit test
sh qdb/tests/run-in-docker.sh     # qdb contract + Quanta parity suites, in Docker
sh docker/tests/run-tests.sh      # container script tests
npm install && npm run docs:check # docs site: check links (docs:build regenerates docs/)
```

## How the engine works (one-minute version)

- **Request:** `src/boot.php` builds an `Environment` (the host selects `sites/<host>/`), loads
  modules, fires hooks `boot` → `load_includes` → JSON actions → `init`, closes the session,
  renders `sites/<host>/index.html` through `Page` (Qtags expand here), then fires `complete`.
- **Hook:** a function `\Quanta\Common\<module>_<hook>(Environment $env, array $vars)` in
  `<module>/hooks/<module>.hook.inc`. Every module defining it runs: `environment` first, then core
  modules alphabetically, then site modules.
- **Classes:** `Quanta\Common\X` lives in `classes/Common/X.class.php`, Qtag `Quanta\Qtags\X` in
  `classes/Qtags/X.qtag.php`. The autoloader resolves them through a class map keyed by file
  basename, so the basename must equal the class name.
- **Nodes:** the URL `/a/b/foo/` renders node `foo`. Only the last segment counts, so node names
  are unique across a site.
- **Templates:** a node renders through the nearest `tpl.html` / `tpl-.html` … / `tpl^.html` in
  its folder lineage, otherwise its body.
- **Qtags:** `[TAG|attr=value|flag:target]`; `{TAG…}` nests inside attributes. Innermost tags
  render first, and passes repeat until nothing changes.

## Rules that bite

1. **New or renamed class → `php doctor <host> class_map`.** The map is only rebuilt when it is
   missing, so a new class is "not found" until then. Rebuild it; don't delete it on a shared volume.
2. **Changed module CSS/JS → `php doctor <host> check`.** Pages use the aggregated
   `css.min.css` / `js.min.js` bundles, not the module files.
3. **Qtag names are `UPPER_SNAKE`:** `[BODY_CLASSES]` → class `BodyClasses`. CamelCase in markup
   maps to `Bodyclasses` and does not autoload.
4. **No `:` inside a Qtag attribute value** (the first `:` starts the target) and no `[ ] { }`
   inside one tag except a nested `{…}` tag.
5. **Rendered HTML has all newlines stripped** before Qtags run: `//` comments in inline JS swallow
   the rest of the script, and `<pre>` loses its line breaks.
6. **Any `[Xx…]` / `{Xx…}` text in output is parsed as a Qtag**, and unknown tags render as empty.
   Escape user content with `Api::string_normalize()` or the `no_qtags` attribute.
7. **Hook functions share one flat namespace.** Any function named `<module>_<something>` in a hook
   file *is* a hook implementation, and two modules can't define the same function.
8. **Hooks receive `$vars` by value** unless the function declares `array &$vars`. Change caller
   state through the objects in `$vars` or through a `&$vars` signature.
9. **Node storage goes through `$env->db()`** (`FilesDb`, or `FilesDbExt` when qdb is loaded).
   Never call `\QuantaDb::` directly. Catch `FilesDbException`, never `QuantaDbException` (undefined
   without the extension). Pass `'at' => $path` when you already hold the folder.
10. **`FilesDb` and `FilesDbExt` must answer identically.** A db method change needs both classes
    and the parity tests (see `docs/reference/recipes.md`).
11. **`.htaccess` and `docker/nginx/quanta.conf` implement the same routes:** change both.
12. **Every top-level file ships into the Docker image's web root** unless `.dockerignore`
    excludes it.
13. **Don't hand-edit generated files:** anything in `static/tmp/`, or the `docs/` files listed in
    `docs/.build-manifest`.
14. **Secrets live in the site's `.env`** (read with `$env->getData('KEY')`), never in code or
    content. The pre-commit hook blocks credential-shaped strings.
15. **The session is closed before the page renders:** session writes from a Qtag or template are
    lost.
16. **Rendering can write:** `[TEXT|tag=…]` creates a translation node on first render, and the
    mail queue is sent during `init`. Don't assume a GET is read-only.

## Code style (as practised)

- Match the file you edit. Most PHP is Drupal-like: 2-space indent, `array()`, `TRUE`/`FALSE`/
  `NULL`, docblocks such as `Implements hook_init().` with `@param Environment $env`. `.phpcs.xml`
  asks for PSR-12, but the code predates it. Lint what you add; don't reformat untouched code.
- `$env` is passed explicitly everywhere; there are no globals.
- `t('Hello !name', array('!name' => $name))` only substitutes placeholders. Translated text is
  `[TEXT|tag=key:Default]` markup.
- Screen and log messages: `new Message($env, $text, Message::MESSAGE_WARNING)`. Structured logs:
  `Logger::get('channel')->error('Charge failed {id}', array('id' => $id))`.
- Comments explain *why*: config files and scripts carry a paragraph for each non-obvious line.
  When you change such a line, update its comment.
- Write the mechanism, not the instance: no hostnames, customer names, ticket ids or one
  deployment's numbers in shared code, comments or docs. Use `<host>`-style placeholders.

## Security notes

- `/qtag/<markup>` and `/q/<TAG>/…` render Qtags taken from the URL. A Qtag must enforce its own
  access and must not change state just because it rendered.
- The API router (`/api/…`) has no auth layer. Each `<module>_api_<method>_<path>` handler checks
  `UserFactory::current($env)` and `NodeAccess::check()` itself.
- Filter user input before it reaches HTML (`Api::filter_xss()`) or Qtag markup
  (`Api::string_normalize()`).
- Site folders sit under the web root. Both `.htaccess` and nginx refuse real `.php`, `.json`,
  `.inc` and `.md` files, and nginx also denies dotfiles and other dev-file extensions. Under
  Apache, any other file in a site folder can be fetched directly, so keep secrets out of node
  folders.

## Definition of done

- **PHP in `src/`:** run `php -l` and `vendor/bin/phpcs` on the changed files, rebuild the class
  map, and exercise the change on a scratch site (`php doctor <host> setup`). If you touched
  `FilesDb*`, `Node*`, `NodeFactory`, `Environment` path code, jobs or the class map, also run
  `sh qdb/tests/run-in-docker.sh parity`.
- **qdb:** see `qdb/AGENTS.md`. **Docker / nginx / php-fpm:** see `docker/AGENTS.md`.
- **Docs site:** run `npm run docs:build && npm run docs:check`, and commit the regenerated `docs/`
  output with its source.
- **Docs stay true:** update the document that describes what you changed (this file,
  `docs/reference/`, `qdb/docs/`) in the same change.

## Commits and pull requests

Imperative, sentence-case subject with no prefix and no trailing period ("Rebuild the class map in
place instead of deleting it"). The body explains why the change is needed and what was wrong
before. Branches are kebab-case, and PRs target `master`.

## Working inside a site

A site (`sites/<host>/`) is a separate repository with its own `AGENTS.md`. New sites get it from
`profiles/generic/`. For an existing site, copy it with
`cp profiles/generic/AGENTS.md profiles/generic/CLAUDE.md sites/<host>/`. Site-specific conventions
belong in the site's file, not here.

## Read more

- [docs/reference/architecture.md](docs/reference/architecture.md): boot sequence, `Environment`,
  module loading, class map, hook mechanism and catalog, caches and generated state
- [docs/reference/content-model.md](docs/reference/content-model.md): nodes on disk, names and
  lookup, lineage, categories, status, permissions, users, site layout
- [docs/reference/templates-and-qtags.md](docs/reference/templates-and-qtags.md): `index.html`,
  template resolution, Qtag syntax and parser rules, writing a Qtag, tag catalog
- [docs/reference/modules.md](docs/reference/modules.md): module anatomy and catalog, overrides,
  assets, forms, actions, Shadow, API, jobs, mail, localization, doctor
- [docs/reference/recipes.md](docs/reference/recipes.md): step-by-step checklists for common changes
- [qdb/AGENTS.md](qdb/AGENTS.md), [qdb/docs/usage.md](qdb/docs/usage.md),
  [qdb/docs/how-it-works.md](qdb/docs/how-it-works.md), [qdb/docs/api-contract.md](qdb/docs/api-contract.md)
- [docker/AGENTS.md](docker/AGENTS.md), and [README.md](README.md) for Docker environment
  variables and logging
