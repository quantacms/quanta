# Modules

How modules are put together, what each core module does, and the subsystems they provide: assets,
forms, actions, the Shadow overlay, the API router, jobs, mail and the `doctor` CLI.

## Module anatomy

```
<module>/                         src/modules/<module>/ (core) or sites/<host>/_modules/<module>/ (site)
├── hooks/<module>.hook.inc       hook implementations, namespace Quanta\Common (the only file loaded eagerly)
├── classes/Common/<Class>.class.php    \Quanta\Common\<Class>   (autoloaded via the class map)
├── classes/Qtags/<Class>.qtag.php      \Quanta\Qtags\<Class>    (one Qtag per file)
├── tpl/*.tpl.php | *.inc | *.html      Qtag-markup templates (not PHP)
└── assets/{css,js,img}/          served at /modules/<module>/assets/…
```

- There is no manifest: a directory is a module. A leading `_` disables it.
- Hook file header: `<?php namespace Quanta\Common;` followed by functions named
  `<module>_<hook>(Environment $env, array $vars)`, each with an `Implements hook_<hook>().`
  docblock.
- Class files: one class per file. The basename must equal the class name, and only the
  `classes/Common` and `classes/Qtags` directories are mapped (not recursively).
- Templates are read with `file_get_contents` and rendered as Qtags, so PHP code in them is not
  executed.
- Vendored front-end libraries live in the module's `assets/` (or `addons/`, as in
  `form/addons/autocomplete`).

## Site modules and overrides

- Put site-specific code in `sites/<host>/_modules/<module>/`, never in `src/modules/`.
- A site module with a **new name** adds hooks, classes and Qtags. It loads after every core
  module, so its hooks run last.
- A site module with the **same name as a core module replaces the core module completely**:
  none of the core module's hooks or classes load. Copy everything you still need, or prefer a new
  module that hooks in.
- A site class whose file basename equals a core class's overrides it in the class map. Use this
  sparingly, because it silently shadows core code.

## Core module catalog

| Module | Purpose |
|---|---|
| `access` | Node and user permission checks (`NodeAccess`, `UserAccess`); the Qtag `filter=` attribute |
| `amp` | Google AMP rendering and AMP Qtags |
| `api` | `Api` utility class (`normalizePath`, `string_normalize`, `filter_xss`, validators, `minify`, `redirect`) and the `/api/` router; generic Qtags (`LINK`, `URL`, `VARIABLE`, `DATE`, …) |
| `block` | `BLOCK` (embed a node), `WRAPPER` |
| `blog` | `BLOG` list |
| `cache` | Per-request cache, node-path shard cache, `doctor clear_cache` |
| `captcha` | `CAPTCHA` Qtag and validation (secret from `.env`) |
| `carousel` | Image carousels |
| `doctor` | The `Doctor` CLI class, symlink repair, `doctor check` |
| `environment` | `Environment`, `DataContainer`, `JSONDataContainer`, `FilesDb` / `FilesDbExt`, `Logger`, `.env` loading, class map |
| `feeds` | RSS feeds at `/feeds/<name>` |
| `file` | Uploads, attachments, file Qtags, serving node files |
| `fontawesome` | Icon Qtags |
| `form` | Qtag forms: `FormState`, `FormFactory`, field classes |
| `gallery` | Image gallery of a node |
| `googleclient`, `googledrive`, `googledocs` | Google OAuth client, Drive permissions, Docs generation/reading |
| `grid` | CSS grid framework and the `grid=` attribute |
| `image` | Images and thumbnails (`static/tmp/<host>/thumbs`) |
| `import` | CSV import of an attached file on save (`file_imported` hook) |
| `integrity` | Repair of translated sub-nodes (`doctor integrity_check`) |
| `jobs` | File-based job queue |
| `jquery` | jQuery, jQuery UI, and `engine.js` (the client side of JSON actions) |
| `jumper` | Quick-navigation dropdown |
| `list` | Node, tree and file lists (`DirList`, `FastDirList`, `FileList`); drag-sorting |
| `localization` | Languages, `[TEXT]` translations, language switcher |
| `mail` | `Mail` (PHPMailer over SMTP), the `_mailqueue`, `MAIL_FORM` |
| `map` | Google Maps |
| `media` | Audio/video players and playlists |
| `menu` | `MENU` |
| `message` | Flash messages (`Message`, `[MESSAGES]`) |
| `meta` | `<head>` meta tags (`[META_DATA]`, `metadata` hook) |
| `node` | `Node`, `NodeFactory`, `NodeTemplate`, node CRUD actions and edit forms |
| `page` | `Page` (the `index.html` shell), layout Qtags, CSS/JS bundle build, profile install |
| `qtags` | `QtagFactory`, base `Qtag` / `HtmlTag`, the `/q/` and `/qtag/` endpoints |
| `shadow` | The overlay editing UI ("Shadow") |
| `sitemap` | XML sitemap |
| `social` | Share buttons, YouTube embeds |
| `stats` | Page timing (`STATS`, admin only); defines constants used elsewhere, so keep it enabled |
| `sync` | `doctor rsync`: copies a site's `db/` between environments with a deployment-specific script |
| `user` | `User`, `UserFactory`, login/registration/profile forms and actions, JWT bearer auth |
| `workflow` | Node status (draft / unpublished / published) |
| `wysiwyg` | CKEditor 5, loaded only when the Shadow editor is open |

## CSS and JavaScript

- Register a file for the bundle with `$env->addInclude($path)` in `<module>_load_includes` (the
  type comes from the extension). `load_includes` doesn't run on `?ajax` requests.
- `php doctor <host> check` concatenates and minifies every include into
  `static/tmp/<host>/files/css.min.css` and `js.min.js`. **Edits to module assets are not live
  until it runs** (Docker images build the bundles at image build time with
  `quanta-build-assets`).
- `[CSS|runlast]` inlines the CSS bundle into a `<style>` element. `[JS|runlast]` emits a
  `<script src>` for the JS bundle, and `refresh` appends a cache-buster.
- Per-page files outside the bundle: `$page->addJS('/modules/<m>/assets/js/x.js')` in
  `<module>_page_init`, or `[CSS|external:/assets/css/site.css]` and `[JS:/assets/js/site.js]`
  (`module=<m>` resolves the path inside a module) in markup.
- Because pages lose their newlines before rendering, inline scripts must not use `//` comments
  or rely on automatic semicolon insertion.

## Forms

Declare a form in markup. Every `INPUT`'s target is the form id:

```
[FORM|name=contact|ok_message=Thanks!:
  [INPUT|type=string|required|name=full_name|title=Name:contact]
  [INPUT|type=email|required|name=email|title=Email:contact]
  [INPUT|type=text|name=message|title=Message:contact]
  [INPUT|type=submit|name=send|value=Send:contact]
]
```

- `[FORM]` adds hidden `form_submit`, `form` (the id) and `form_type` fields. Attributes:
  `name` (id), `type`, `ajax`, `redirect`, `ok_message`, `action`, `method`.
- `INPUT|type=<t>` selects `FormItem<T>` (`string`, `text`, `hidden`, `select`, `checkbox(es)`,
  `radio(s)`, `date`, `time`, `number`, `email`, `url`, `tel`, `password`, `file`, `color`,
  `rating`, `address`, `autocomplete`, `submit`). Options for select/radios come from `OPTION`,
  `LIST_OPTIONS` (child nodes) or `LIST_VALUES`.
- **Submission is processed during the `boot` hook** (`form_boot`): when `$_REQUEST['form']` is
  set, `FormState::checkValidate()` fires, in order: `form_pre_validate`,
  `<id>_form_pre_validate`, `form_validate`, `form_type_<type>_validate`, `<id>_form_validate`.
  Then, if there are no validation errors: `form_submit`, `form_type_<type>_submit`,
  `<id>_form_submit`, `form_type_<type>_after_submit`, `<id>_form_after_submit`. Otherwise
  `form_validation_errors`.
- Handlers: `function <module>_<id>_form_validate(Environment $env, array &$vars)` and
  `…_form_submit`. `$vars['form_state']` is the `FormState`. Read values with
  `$form_state->getData('<field>')` and reject with
  `$form_state->validationError('<field>', t('…'))`. **Use underscores in form ids that need
  hooks**, because a hyphen can't appear in a function name.
- Field objects register in the `FormState` only while the form *renders*, which is after boot.
  So submit hooks should read submitted values with `getData()`, not `getItems()`. Per-field
  checks such as `required` and length run when the field renders (`form_item_validate`).
- `?async_submission` validates only and returns the errors as JSON (used by `ajax` forms).
- `FormItem`'s default validation messages are Italian source strings passed through
  `Localization::translatableText()` (e.g. key `required-error-message`). Translate them through
  their `i18n-*` nodes.

## JSON actions

The second request path, used by Shadow, node editing and login. The client (`engine.js`)
POSTs a field `json` whose `action.value` names the action. `Environment::checkActions()` fires
`action_<value>` with `array('action' => …, 'data' => (array) $request_json)` and then **exits**.
A handler is `function <module>_action_<value>(Environment $env, array $vars)`. It checks access
itself and prints its JSON response (typically `exit(json_encode($response))`).

Node actions (`node_add`, `node_edit`, `node_delete`, `node_duplicate`, …) go through
`NodeFactory::requestAction()`, which fires `node_presave`, `shadow_<form>_pre_validate`, saves, and
then fires `<action>_complete`. The response JSON carries `redirect`, `shadow` or `shadowErrors`.

## Shadow (overlay UI)

The editing overlay is a `Shadow` (`class Shadow extends Page`). A request with
`?shadow={"context":"node_edit","components":[…],"widget":…}` sets the context (`shadow_boot`).
`shadow_init` then fires `shadow_<component>` for each component and renders
`shadow/tpl/<widget>.inc`. A module adds a tab with
`$vars['shadow']->addTab($title, $html, $weight)` (and `addButton()`), usually loading Qtag markup
from its own `tpl/*.inc`. Submitting the overlay sends a JSON action.

## API router

Any URI containing `/api/` is handled in `api_boot`:

1. The path after `/api/` has `-` turned into `_`.
2. Routes registered through `api_routes` are matched first. In
   `function <module>_api_routes(Environment $env, array &$vars)`, append patterns such as
   `'1/{id}/pricing_categories'` to `$vars['routes']`. Literal segments form the hook name (a
   leading numeric version is dropped), and `{…}` segments are captured into
   `$vars['path_parts']`.
3. Without a matching route, numeric segments become `path_parts`, and the rest form the name.
4. The router fires `api_<get|post|put|patch|delete>_<name>` with `$vars['data']` (query string,
   `$_POST`, or the parsed body).
5. **The handler must print its response and `exit`.** Otherwise the router answers 404
   `{"success":false,"error_code":"api_not_found",…}`.

There is no authentication layer. Handlers call `UserFactory::current($env)` (session or
`Authorization: Bearer` JWT) and the access checks themselves.

## Jobs

- `JobsFactory::queueJob($env, $type, $source, $data)` creates a job node in `_jobs_todo`.
- `JobsFactory::processQueue($env)` runs pending jobs. Each job fires `job_run_<type>`, and the
  handler sets `$vars['completed'] = TRUE` (optionally `log` / `response`). Jobs without a handler
  move to `_jobs_unknown`. Retries are bounded.
- **Core never calls `processQueue()`.** A site module, cron entry or worker must.

## Mail

`Mail extends Node`: queued messages are nodes in `_mailqueue`, sent through PHPMailer with SMTP
settings from the mail node's data. `mail_init` sends the queue **on every page request**.
`MAIL_FORM` is a `FORM` with `type=mailform`, and `mailform_<id>_send` lets a module adjust the
message.

## Doctor (CLI)

```
php doctor <host> <command> [--key=value …]      # from the repository root (it includes src/boot.php)
```

| Command | What it does |
|---|---|
| `setup` | `doctor_pre_setup` (user, web server, system dirs, empty `.env`, `clear_cache`, `check`), then each module's `doctor_setup`: copy the profile if `index.html` is missing, create system nodes, language `en`, roles, an `administrator` user, job and status nodes. Options: `--profile=<dir>`, `--web-server-type=1` (Apache) or `2` (nginx), `--web-server-user=`, `--admin-pass=` |
| `check` | Rebuild the class map, reindex the node store and repair broken symlinks, rebuild the CSS/JS bundles |
| `class_map` | Rebuild the class map only |
| `clear_cache` | Remove `static/tmp/<host>/cache` and thumbnails |
| `integrity_check --path=<node> [--subnodes=a,b]` | Repair translated sub-nodes |
| `rsync --env=<env> …` | Environment sync (deployment-specific; read `sync/` before use) |

A command maps to the hook `doctor_<command>`, with `-` turned into `_` (`check` maps to the hook
`doctor`). A module adds a command by implementing `<module>_doctor_<command>(Environment $env,
array $vars)`, which uses `$vars['doctor']` (`op()`, `ok()`, `ko()`, `ask()`, `execute()`).
Options are passed as `--key=value`; write `--flag=1`, because a bare `--flag` becomes NULL. Each run
touches `static/tmp/<host>/doctor_recipe.txt`.

Started as root (for example through `docker exec`), `doctor` first switches to the owner of
`sites/<host>`, so everything it writes stays writable by php-fpm. `QUANTA_DOCTOR_AS_ROOT=1`
keeps root, for callers that fix ownership themselves, like the container entrypoint.
