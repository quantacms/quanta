# Architecture

How a Quanta request runs: from the front controller through module loading, the class map and
hooks, to rendering. Paths are relative to the repository root. Code references name the file and
the function or method, not line numbers.

## Request lifecycle

The web server sends every non-static request to `src/boot.php` (`.htaccess`,
`docker/nginx/quanta.conf`). The `doctor` CLI includes the same file with `$doctor_cmd` set.
`boot.php` does this, in order:

1. `require_once` of `DataContainer`, `Environment`, `Logger` (PHP errors go to the JSON logger
   from here on) and `UserFactory`. These load outside the class map on purpose, because on a fresh
   site the map doesn't exist yet.
2. `new Environment($host, $request_uri, $docroot)` parses the request (see below).
3. `src/autoload.php` registers the class-map autoloader, then Composer's `vendor/autoload.php`.
4. `$env->load()` discovers and loads the modules. If `CLASS_MAP_FILE` doesn't exist, it calls
   `$env->mapClasses()`.
5. `$env->startSession()`.
6. `FileFactory::checkFile()` / `FileFactory::downloadPdf()` serve a node attachment and exit
   when the request is for one.
7. Hook **`boot`**. Most routing happens here: `api_boot` handles `/api/…`, `form_boot` validates
   and submits Qtag forms, `shadow_boot` switches to the overlay UI, `environment_boot` reads the
   site's `.env`.
8. Hook **`load_includes`** registers CSS/JS (skipped when `$_REQUEST['ajax']` is set).
9. If run by `doctor`: `new Doctor(...)->cure()` runs the command and exits.
10. `$env->checkActions()`: if the POST field `json` carries `action.value`, it fires
    **`action_<value>`** and exits (see `modules.md#json-actions`).
11. Hook **`init`**. `page_init` creates the `Page` and `mail_init` sends the mail queue.
    `feeds_init` (`/feeds/<name>`) and `shadow_init` (overlay requests) render their own output
    and exit.
12. `$env->closeSession()` releases the session lock **before** rendering, so concurrent requests
    from one user don't serialise. Session writes after this point are lost.
13. `print $env->getData('page')->render()`. `Page::buildHTML()` loads `sites/<host>/index.html`
    (or only the current node for `?ajax=1`) and fires `page_preload`, `page_init`,
    `body_classes`, `page_after_build`, `page_complete` (where `qtags_page_complete` expands all
    Qtags) and `page_render`.
14. Hook **`complete`**, then `exit()`.

`boot.php` also defines the global `t()` helper (`Localization::t()`).

## Environment

`Quanta\Common\Environment` (`src/modules/environment/classes/Common/Environment.class.php`) is
the per-request context. It is a `DataContainer`: `setData($key, $value)`,
`getData($key, $default)` (a stored `NULL` reads back as the default) and `addData()` (merges
arrays, adds numbers, concatenates strings). Everything receives it explicitly as `$env`; there is
no global.

**Request fields:** `$env->host` (`HTTP_HOST` without the port), `$env->request_uri` (no query
string; `/` becomes `/home/`), `$env->request` (the URI split on `/`), `$env->request_path` (the
last segment, which selects the node), `$env->query_params`, `$env->request_json`
(`json_decode($_REQUEST['json'])`).

**Directories** (`$env->dir[...]`, set in the constructor):

| Key | Path |
|---|---|
| `quanta` | repository root |
| `docroot` | `sites/<host>` |
| `db` | `sites/<host>/db` |
| `users` | `sites/<host>/db/_users` |
| `statuses` | `sites/<host>/db/_statuses` |
| `tpl` | `sites/<host>/_tpl` |
| `modules_core` | `src/modules` |
| `modules_custom` | `sites/<host>/_modules` |
| `profiles` | `profiles` |
| `tmp` | `static/tmp/<host>` |
| `tmp_files` | `static/tmp/<host>/files` (CSS/JS bundles) |
| `trashbin` | `static/tmp/<host>/trashbin` (deleted nodes) |
| `log` | `static/tmp/<host>/log` |

Modules add more at boot with `$env->sysdir($name, $folder)` (under the docroot) and
`$env->tmpdir($name, $folder)` (under `tmp`): `cache`, `pages`, `system`, `languages`,
`translations`, `roles`, `mailqueue`, …

**Site configuration:** `environment_boot` reads `sites/<host>/.env` (`KEY=VALUE` lines, `#`
comments) into environment data. Read values with `$env->getData('KEY')`. API keys, SMTP
credentials and JWT secrets come from here.

**Storage accessor:** `$env->db()` returns the node-storage implementation: `FilesDbExt` when the
qdb extension class `QuantaDb` exists, otherwise `FilesDb`. Both are `require_once`d directly. See
`content-model.md#lookup` and `qdb/docs/usage.md`.

Other notable methods: `nodePath($name)` (name → folder), `getContext()` / `setContext()` (the
current UI context, e.g. `node_edit`, `qtag`), `addInclude($path)` (register CSS/JS for the
bundle), `hook()`, `checkActions()`, `scanDirectory()`.

## Modules

A module is any directory in `src/modules/` (core) or `sites/<host>/_modules/` (site). There is no
manifest and no enable switch.

- `Environment::load()` loads core first, then site modules. `scanDirectory()` returns them sorted,
  and skips `.git`, `assets`, `files` and anything starting with `_`. **Rename a module to `_name`
  to disable it.**
- Modules are keyed by name. A site module with the same name as a core module **replaces it
  entirely**: the core hook file and classes are no longer loaded.
- Loading a module only `require_once`s `<module>/hooks/<module>.hook.inc`. Classes load lazily
  through the class map.
- There is no dependency declaration (`dependsFrom()` exists but nothing calls it). Modules use
  each other's classes freely. Some core code relies on other modules being present (e.g. `stats`
  defines constants used during rendering), so don't disable core modules casually.

Module anatomy and the full catalog are in `modules.md`.

## Class map and autoloading

- `CLASS_MAP_FILE` is `static/tmp/<host>/class_map.dat` (defined in `src/autoload.php`).
- `Environment::mapClasses()` lists `<module>/classes/Common/` and `<module>/classes/Qtags/` of
  every loaded module, without recursing. It stores `[Common|Qtags][<basename up to the first
  dot>] = <path>` and writes the map to a temporary file that is renamed into place, so readers
  never see a truncated map.
- The autoloader handles only three-part names, `Quanta\Common\X` or `Quanta\Qtags\X`. It loads
  the map once per process and looks `X` up **case-sensitively**.
- So the class file's basename must equal the class name. The suffix (`.class.php`, `.qtag.php`,
  `.interface.php`) is a convention, not a rule. Modules load in order (core, then site), so a
  later file with the same basename wins: a site module can shadow a core class.
- **The map is rebuilt only when it is missing** (`boot.php`). After adding or renaming a class,
  run `php doctor <host> class_map` (or `check`). The Docker entrypoint runs `class_map` on every
  start.

## Hooks

`Environment::hook($name, array &$vars = array())` calls every loaded module's function
`\Quanta\Common\<module>_<name>($env, $vars)` that exists:

- **Order:** `environment` first, then the remaining modules in load order (core alphabetically,
  then site modules). The list of implementers is cached per hook name for the request.
- **Return value:** `TRUE` if at least one module implemented the hook (`doctor` uses this to
  report an unknown command).
- **Naming:** the hook file must declare `namespace Quanta\Common;`. The module name is a prefix,
  not a separator, so `node_page_init` is module `node` implementing `page_init`, and `page_init`
  is module `page` implementing `init`. Every function in a hook file whose name starts with
  `<module>_` is a candidate hook. All hook functions share one namespace, so names must be unique
  across modules.
- **`$vars` and references:** `hook()` takes `$vars` by reference, but an implementer declared
  as `function x_y(Environment $env, array $vars)` receives a copy. Changes reach the caller only
  through objects inside `$vars` (`'node' => &$node`, `'form_state' => &$this`), through slots the
  caller bound by reference, or when the implementer declares `array &$vars`. Hooks that collect
  values (`api_routes`, for example) need `&$vars`.

### Hooks fired by core

| Area | Hooks |
|---|---|
| Boot | `boot`, `load_includes`, `init`, `complete` |
| Actions | `action_<name>` (from `checkActions()`), `<action>_complete` (e.g. `node_edit_complete`) |
| Page | `page_preload`, `page_init`, `body_classes`, `page_after_build`, `page_complete`, `page_render`, `metadata` |
| Nodes | `node_open` (after load; access sets `forbidden`), `node_build` (after JSON is read), `node_presave`, `node_save`, `node_after_save`, `node_delete`, `link_alter`, `list_item` |
| Users | `user_open`, `user_load`, `user_presave`, `user_save`, `user_after_save`, `user_login`, `user_wrong_login`, `user_logout`; `User::save()` also fires a hook named after the current context |
| Qtags | `qtag_preload` (before render: change attributes, deny access), `qtag` (after render) |
| Forms | `form_pre_validate`, `<id>_form_pre_validate`, `form_validate`, `form_type_<type>_validate`, `<id>_form_validate`, `form_submit`, `form_type_<type>_submit`, `<id>_form_submit`, `form_type_<type>_after_submit`, `<id>_form_after_submit`, `form_validation_errors`, `form_item_validate`, `<id>_form_item_validate` |
| Shadow | `shadow_<component>` (build overlay tabs), `shadow_<form>_pre_validate` |
| API | `api_routes`, `api_get_<path>`, `api_post_<path>`, `api_put_<path>`, `api_patch_<path>`, `api_delete_<path>` |
| Doctor | `doctor` (the `check` command), `doctor_<command>` (`-` becomes `_`), `doctor_pre_setup`, `doctor_setup` |
| Other | `fallback_language`, `job_run_<type>`, `mailform_<id>_send`, `feed_<name>`, `feed_<name>_item`, `file_imported`, `google_doc_before_generate`, `google_doc_generated`, `google_doc_readed` |

To list the implementers of a hook: `grep -rn "function [a-z_]*_<hook>(" src/modules sites/<host>/_modules`.

## Caching and generated state

- **Per-request cache:** `Cache::get($env, $bin, $key)` / `Cache::set(...)` keep values in
  environment data for the current request only. Bins include `node`, `qtag`, `qtag_markup`
  (identical Qtag markup renders once per request) and `access`.
- **Persistent, generated, never hand-edited** (all under `static/tmp/<host>/`):
  - `class_map.dat`
  - `cache/`: sharded symlinks from node name to folder, plus `__MISSING__` negative markers
  - `files/css.min.css`, `files/js.min.js`: the bundles built by `doctor check`
  - `thumbs/`: image thumbnails
  - `trashbin/`: deleted nodes
  - `doctor_recipe.txt`: its modification time is the cache-buster for `[JS|refresh]`
- `php doctor <host> clear_cache` removes `cache/` and the thumbnails. A stale `__MISSING__`
  marker can hide a node created behind Quanta's back until then.
- qdb's shared-memory index (when present) lives outside the tree; see
  `qdb/docs/how-it-works.md`.

## Logging and errors

- `\Quanta\Common\Logger::get($channel)->info|warning|error(...)` writes one JSON object per line,
  with PSR-3 levels and `{key}` placeholders from the context. PHP warnings and fatals go to the
  same log on channel `php`. `QUANTA_LOG_LEVEL` and `QUANTA_LOG_OUTPUT` configure it (see
  `README.md`).
- `new Message($env, $text, $severity, $type)` queues a message for the screen (`[MESSAGES]`) or
  the log. Severities are `Message::MESSAGE_ERROR|WARNING|NOTICE|CONFIRM|GENERIC`; types are
  `MESSAGE_TYPE_SCREEN|LOG|VALIDATION`.
- Storage errors throw `FilesDbException`. "Not found" is never an exception: you get `NULL`,
  `FALSE` or an empty array.
- Action, API and form-async handlers answer by printing JSON and calling `exit`.
