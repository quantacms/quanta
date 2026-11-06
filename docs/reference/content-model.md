# Content model

Quanta has no SQL database: the filesystem under `sites/<host>/` *is* the database. This document
covers what a node is on disk, how names map to folders, and how status, permissions, users and
languages are stored.

## A site on disk

```
sites/<host>/                  site docroot (often its own git repository)
├── index.html                 page shell: every page renders inside it
├── .env                       KEY=VALUE configuration and secrets (never committed)
├── _modules/<module>/         site modules (same layout as core modules)
├── _tpl/                      template overrides by node name (<node>_tpl.html, …)
├── files/  assets/            static files, served at /files/… and /assets/…
├── pages/                     the profile's page tree (home, blog, …)
├── _components/               layout nodes (_header, _footer, _logo, …) rendered via Qtags
├── _system/                   system nodes such as 403 and 404
├── jobs/                      job queue nodes (_jobs_todo, _jobs_done, _jobs_unknown)
└── db/
    ├── _users/<name>/         one node per user
    ├── _roles/                role nodes (anonymous, logged, admin)
    ├── _languages/            language nodes (en, …)
    ├── _translations/         i18n-<key> nodes created by [TEXT]
    └── _statuses/             workflow status nodes
```

`doctor setup` creates this tree from `profiles/<profile>/` (it copies the profile's files) and
adds the system nodes, the `en` language, the roles and an `administrator` user. Generated state
for the site lives outside it, in `static/tmp/<host>/` (see `architecture.md`).

## Nodes

A **node** is a directory anywhere under the site docroot:

| File | Meaning |
|---|---|
| `data.json` | the language-neutral document |
| `data_<lang>.json` | one translation per language (`data_en.json`, …) |
| `tpl.html`, `tpl-.html`, `tpl^.html`, … | templates for this node or its descendants (`templates-and-qtags.md`) |
| other files (`cat.jpg`, `report.pdf`) | attachments, listed by `[FILES]` and served at `/<node>/<file>` |
| subdirectories | child nodes |
| symlinks to other node folders | categories / tags |

Folders named `files` or `assets` are payload directories, not nodes, and are never listed as
children.

**Standard JSON fields:** `Node::updateJSON()` writes `name`, `title`, `teaser`, `body`, `author`,
`thumbnail`, `timestamp`, `weight`, `status`. Optional `permissions` (below). Any other key is
custom data: read it with `$node->getAttributeJSON('key')` (escapes Qtag characters by default) and
write it with `$node->setAttributeJSON('key', $value)`, or show it with `[ATTRIBUTE|name=key]`.

Example (`profiles/generic/pages/data.json`):

```json
{"permissions":{"node_add":"admin","node_edit":"admin","node_delete":"admin","node_view":"anonymous"},
 "teaser":"Welcome in my blog","author":"administrator","body":"","title":"Blog",
 "thumbnail":null,"timestamp":1541094900,"status":"node-status-published","breadcrumb_exclude":1}
```

## Names, URLs and lookup

- **A node's name is its folder's basename, and it must be unique across the whole site.** Two
  folders with the same name produce a "more than one folder" warning and an unpredictable result.
  `Environment::getCandidatePath()` adds a suffix when creating a node whose name is taken.
- `NodeFactory::buildNode()` normalises new names with `Api::normalizePath()`: accents removed,
  lowercase, spaces and `_` become `-`, anything outside `[a-z0-9-]` is dropped (unless
  `skip_normalize` is passed).
- **URL → node:** only the **last** path segment matters. `/en/animals/cat/` and `/cat/` both
  render node `cat`. A leading language segment (`/en/…`) sets the language. The web server forces
  a trailing slash on extensionless URLs.
- Names starting with `_` are system/hidden nodes: excluded from listings by default, and never
  "published" for anonymous visitors. A name starting with `-` never resolves (it always comes from
  a caller concatenating onto an empty string).

### Lookup

Name → folder resolution (`$env->nodePath($name)` → `FilesDb::path()`) tries, in order:

1. a per-request memo (hits and misses),
2. the shard symlink cache in `static/tmp/<host>/cache/` (with on-disk `__MISSING__` markers),
3. the qdb shared-memory index, when the extension is loaded and coherent (`FilesDbExt`),
4. `find` over the docroot, with a time budget.

Consequences:

- When you already know a node's folder, pass `'at' => $path` to the `$env->db()` methods so no
  lookup runs.
- After moving or renaming a folder outside Quanta's API, clear the cached path
  (`$env->nodePath($name, FALSE, TRUE)`) or run `php doctor <host> clear_cache`.
- All node reads and writes go through `$env->db()`. Writes are locked and atomic (temp file +
  rename). See `qdb/docs/usage.md` for the method list and semantics.

## Working with nodes in PHP

| Call | What it does |
|---|---|
| `NodeFactory::load($env, $name, $lang = NULL)` | Load by name, falling back to the fallback language when the translation is missing; fires `node_open` (access sets `$node->forbidden`) |
| `NodeFactory::loadOrCurrent($env, $name)` | Like `load`, but an empty name means the current node (what most Qtags use for their target) |
| `NodeFactory::current($env)` | The node selected by the URL (empty in the `node_add` / `qtag` contexts) |
| `NodeFactory::buildNode($env, $name, $father, $vars)` | Create and save a node under `$father` |
| `NodeFactory::fastLoadFromRealPath(...)` | Load from a known folder, skipping hooks and access checks (use with care) |
| `$node->save()` / `$node->delete()` | Persist (`node_presave` → `node_save` → `node_after_save`) / move to the trash bin (`node_delete`) |
| `$node->getFather()`, `getLineage()`, `hasParent($name)` | Parent, all ancestors from the docroot down, ancestry test |
| `$node->hasChildren()`, `hasChild($name)`; `$env->db()->children(...)` to list | Children (subfolders) |
| `$node->getCategories()` | Nodes linked into this one by symlink |
| `$node->exists`, `isNew()`, `isForbidden()`, `isPublished()`, `hasTranslation($lang)` | State checks |
| `NodeFactory::linkNodes()` / `unlinkNodes()` | Add / remove a category symlink |
| `$env->db()->move(...)` | Move a node folder (there is no `NodeFactory` wrapper) |

The **father** is the parent folder; when the parent is the site docroot, the father is `home`. The
**lineage** is every ancestor folder, keyed by name, excluding the node itself. Lineage drives
template inheritance and permission inheritance.

## Status and workflow

`status` is one of `node-status-published`, `node-status-unpublished`, `node-status-draft`
(`Node::NODE_STATUS_*`). An empty status counts as published. The `workflow` module maps the
edit form's status field onto the node in `node_presave`. Status nodes live in `db/_statuses`.

## Permissions

`data.json` may contain `permissions` with the actions `node_view`, `node_add`, `node_edit`,
`node_delete`. Each value is a role name, an array of roles, `author` (the node's author), `self`
(the user *is* the node, or one of its ancestors), or `inherit`.

- A missing or `inherit` value is taken from the nearest ancestor that sets it. With no value
  anywhere, the default is `anonymous` for `node_view` and `admin` for everything else.
- `anonymous` grants everyone. Otherwise the user needs one of the listed roles exactly. Roles are
  not hierarchical: `admin` doesn't satisfy a permission set to another role.
- Check in code with `NodeAccess::check($env, Node::NODE_ACTION_EDIT, array('node' => $node))`
  (optionally `'user' => $user`). `duplicate` maps to `add` and `change_author` to `edit`. Results
  are cached per request.
- View access is enforced on load (`access_node_open` sets `forbidden`). `node_page_init` then
  redirects to `/403` or `/404`.
- `UserAccess::check()` covers user actions (`user_edit`, `user_edit_own`, `user_register`, …).
- Qtags can be shown or hidden per request with the `filter` attribute
  (`templates-and-qtags.md#access-filters`).

## Users and sessions

- A user is a node in `db/_users/<name>/` (`class User extends Node`). Its JSON holds `roles`,
  `password` (hashed by `UserFactory::passwordEncrypt()`), `email`, names and custom fields.
- The built-in roles are `anonymous`, `logged` and `admin` (`User::ROLE_*`). Logging in adds
  `logged`.
- `UserFactory::current($env)` resolves the current user from the session, then from an
  `Authorization: Bearer` JWT (HS256, secret `JWT_SECRET_KEY` from `.env`), and falls back to
  anonymous.
- `$user->hasRole($role)`, `UserFactory::load($env, $name)`, `UserFactory::buildUser(...)`,
  `UserFactory::getUserFromField(...)`.
- The session is started at boot and closed after the `init` hook, before render. Session writes
  belong in `boot`/`init` hooks, actions or form handlers, not in Qtags or templates.

## Languages and translations

- Languages are nodes in `db/_languages/`. The current language comes from environment data, then
  the session, then the fallback language (`Localization::getLanguage($env)`).
- A node's translations are its `data_<lang>.json` files. `NodeFactory::load()` falls back to the
  fallback language when one is missing.
- Interface strings: `[TEXT|tag=key:Default text]` looks up node `i18n-<key>` in
  `db/_translations/` and **creates it on first render** if it is missing. `t()` only substitutes
  `!x` / `%x` placeholders; it doesn't translate.
