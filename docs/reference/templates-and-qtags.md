# Templates and Qtags

What a page is made of: the page shell, node templates and how they are chosen, and the Qtag
markup that fills them, including the parser rules, writing your own Qtag and the core tag
catalog.

## The page shell: `index.html`

Every full page renders inside `sites/<host>/index.html`. It is plain HTML with Qtags. The
generic profile's shell (abridged):

```html
{VARIABLE|set=Quanta CMS example:site_name}
<html lang="[LANGUAGE]">
<head>
  [META_DATA]
  [CSS|runlast]                                         <!-- inlined CSS bundle -->
  [CSS|runlast|refresh|external:/assets/css/style.css]
  <title>[TITLE] - {VARIABLE:site_name}</title>
</head>
<body class="grid three-rows [BODY_CLASSES]">
  [MESSAGES]
  [HEADER|id=header:_header]                            <!-- renders node _header -->
  [MAIN|id=grid-main|class=grid]                        <!-- renders the current node -->
  [FOOTER|id=footer:_footer]
  [JS|runlast]                                          <!-- the JS bundle -->
</body>
</html>
```

- `[MAIN]` / `[CONTENT]` render the **current** node (the one the URL selects) through its
  template. `[CONTENT:_logo]` renders another node, a "component", through *its* template.
- Qtags without a target inside a component (`[TITLE]`) still refer to the **current** node.
- `?ajax=1` renders only the current node, without the shell.

## Node templates and how one is chosen

`NodeTemplate::buildTemplate()` picks the first match:

1. **Explicit:** `[RENDER|tpl=<name>|module=<module>:<node>]` uses `<module>/tpl/<name>.tpl.php`
   of that core or site module.
2. **The node's own `tpl.html`.**
3. **`sites/<host>/_tpl/<node>_tpl.html`** (an override kept outside the content tree).
4. **Depth templates in the lineage**, nearest wins: an ancestor's `tpl-.html` applies to its
   children, `tpl--.html` to grandchildren, up to five dashes. The `_tpl/<ancestor>_tpl-.html`
   variants work too.
5. **Catch-all:** the nearest `tpl^.html` (or `_tpl/<node>_tpl^.html`) on the node itself or an
   ancestor applies to every descendant.
6. **No template:** the node's `body` is rendered as is.

In a template, `[NODEITEM]` is replaced by the node's name.

Example from `profiles/generic/_example_data/`: `animals/tpl.html` renders the `animals` page,
`animals/tpl--.html` would render a grandchild such as `animals/cat/<breed>`, and
`_example_data/tpl--.html` covers grandchildren of `_example_data` (children of `animals`, like
`cat`, because `animals` has no `tpl-.html`). `pages/tpl^.html` lays out every page under
`pages/`.

**Module template files** (`src/modules/<m>/tpl/*.tpl.php`, `*.inc`, `*.html`) contain **Qtag
markup, not PHP**: they are read with `file_get_contents`. List templates (`list/tpl/`,
`blog/tpl/blog.tpl.php`) use the placeholder `[LISTITEM]` for the current row's node name:

```
<h3>[LINK:[LISTITEM]]</h3>
<div class="thumb">[THUMBNAIL|640x480:[LISTITEM]]</div>
<div class="teaser">[TEASER:[LISTITEM]]</div>
```

## Qtag syntax

```
[TAG]                         no attributes, no target
[TAG:target]                  target, usually a node name
[TAG|attr=value|flag:target]  attributes separated by |; a bare name is TRUE
{TAG|…:target}                same thing with braces, used to nest inside another tag's attribute
```

Real examples:

```
[TITLE]                                         title of the current node
[LINK|title=Main page:home]                     link to node home
[MENU|links={LINK:fruits}---{LINK:animals}]     nested tags inside an attribute
[LIST_NODES|tpl=title_image_linked:fruits]      children of fruits through a list template
[TEXT|tag=read-more:Read more]                  translatable string
[INPUT|type=string|required|name=email:contact] a form field of form "contact"
```

### Parser rules (`QtagFactory`)

- **Matching:** `/\[[A-Z][^\[\]\{\}]+\]/` (and the `{}` equivalent). A tag starts with an
  uppercase letter and contains no bracket or brace, so only innermost tags match.
- **Nesting** works by repetition: each pass renders the innermost tags, and the output is scanned
  again until nothing changes (at most `MAX_TRANSFORM_PASSES` = 100). A tag whose output contains
  its own markup stops at that limit and leaves a warning.
- **Splitting:** the tag is split on the **first `:`**, and everything after it is the target.
  The left side is split on `|`, each attribute on its first `=`. So **an attribute value can't
  contain `:`**, while a target can.
- **Tag name → class:** split on `_`, capitalise each part: `BODY_CLASSES` →
  `\Quanta\Qtags\BodyClasses`. `BodyClasses` in markup becomes `Bodyclasses`, which the
  case-sensitive class map doesn't know. **Always write tags in `UPPER_SNAKE`.**
- **Unknown tags** fall back to the base `Qtag`, which renders nothing: the markup disappears.
  `highlight` / `showtag` force that fallback, to display a tag instead of rendering it.
- **Memoisation:** identical markup renders once per request and the result is reused. A tag that
  should differ per occurrence needs different markup.
- **Page phase:** `qtags_page_complete` first strips **every newline** from the page, then runs a
  normal pass and a `runlast` pass. A tag with `runlast` waits for the second pass. `[CSS]` and
  `[JS]` use it so every include is registered before the bundle tag renders.
- Any text in output that looks like a tag (`[Note]` is fine, `[NOTE]` is not) is parsed. Escape
  untrusted content with `Api::string_normalize()` or the `no_qtags` attribute.
- **Rendering from the URL:** `/q/<TAG>/<urlencoded attrs>` and `/qtag/<urlencoded markup>`
  render a Qtag directly in context `qtag` (used by AJAX widgets). Treat every Qtag as reachable
  from the outside.

### Attributes every Qtag understands

| Attribute | Effect | Handled by |
|---|---|---|
| `prefix`, `suffix` | Add text around non-empty output | `Qtag::load()` |
| `trim=<n>`, `trim_text` | Truncate output to n characters, appending `trim_text` | `Qtag::load()` |
| `empty_replace`, `allowed_empty_tags` | Replacement when output is empty or only tags | `Qtag::load()` |
| `no_qtags` | Escape Qtag characters in the output | `Qtag::load()` |
| `runlast` | Render in the second pass | `QtagFactory` |
| `highlight`, `showtag` | Show the tag instead of rendering it | `QtagFactory` |
| `attributes=<node>` | Merge that node's JSON into the attributes | `qtags.hook.inc` |
| `grid=<classes>`, `grid_html_tag`, `grid_id`, `grid_no_inner` | Wrap output in a CSS-grid cell | `grid_qtag` |
| `filter=…`, `filter_node`, `filter_key`, `or_logic` | Render only when criteria match (below) | `access_qtag_preload` |

`HtmlTag` subclasses (most layout tags) also take `id`, `class`, `html_tag` (change the element),
`attr-<name>=<value>` (any HTML attribute) and `html_escape_params`.

### Access filters

`filter=<type>@<v1>;<v2>,<type2>@<v>` renders the tag only if every criterion matches (any of
them with `or_logic`). Values separated by `;` are alternatives, a leading `!` negates a value, and `__empty__` matches an
empty value.
`filter_node=<name>` evaluates against another node instead of the current one.

Types: `user_role`, `user_name`, `user_can_edit`, `author`, `path` (node name), `father`,
`lineage`, `status`, `language`, `context`, `shadow`, `category`, `thumbnail`, `file_name`,
`referer`, `env`.

```
[EDIT|filter=user_role@admin;editor]        only for admins and editors
[TEXT|filter=context@user_edit_own:…]        only inside the own-profile form
[BLOCK|filter=path@!home:_promo]             everywhere except the home page
```

Filters hide output. They don't replace server-side checks: handlers and actions must still call
`NodeAccess::check()`.

## Writing a Qtag

```php
<?php
// src/modules/<module>/classes/Qtags/Hello.qtag.php  →  [HELLO|name=World:target]
namespace Quanta\Qtags;
use Quanta\Common\Api;
use Quanta\Common\NodeFactory;

/**
 * Renders a greeting for a node.
 */
class Hello extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $name = $this->getAttribute('name', 'World');
    return 'Hello ' . Api::filter_xss($name) . ' from ' . Api::string_normalize($node->getTitle());
  }
}
```

- Extend `Qtag` for raw output, or `HtmlTag` for output wrapped in an element (set
  `$this->html_tag`, `$this->html_body`, call `addClass()`).
- Available: `$this->env`, `$this->getTarget()`, `$this->getAttribute($name, $default)` (the
  default applies when the value is *empty*), `hasAttribute()`, `setAttribute()`,
  `$this->attributes`. Override `build()` for set-up that runs in the constructor.
- Return a string. Returned markup may contain more Qtags; they render in the next pass.
- Escape what you output: `Api::filter_xss()` for HTML, `Api::string_normalize()` for anything
  that could otherwise be parsed as Qtags.
- Then run `php doctor <host> class_map`: the tag renders as empty until the class is mapped.
- Other modules can change a tag's attributes or deny it in `<module>_qtag_preload`, and change its
  output in `<module>_qtag` (both take `array &$vars`; `$vars['qtag']` is the object).

## Core Qtag catalog

Grouped by module (class files in `src/modules/<module>/classes/Qtags/`). Read the class for its
attributes.

| Module | Tags |
|---|---|
| node | `TITLE`, `BODY`, `TEASER`, `AUTHOR`, `STATUS`, `ATTRIBUTE` (`name=` a JSON key), `CATEGORIES`, `CONTENT` (rendered node with wrappers), `RENDER` (no wrappers; `tpl=`, `module=`), `ADD`, `EDIT`, `DELETE`, `DUPLICATE`, `CHANGE_AUTHOR`, `CATEGORY_TOGGLE`, `OPERATIONS` (permission-checked action links) |
| page | `HEADER`, `MAIN`, `FOOTER`, `ASIDE`, `SECTION` (layout regions), `BREADCRUMB`, `BODY_CLASSES`, `CONTEXT`, `CSS`, `JS` |
| api | `LINK` (link to a node: `title`, `link_class`, `link_target`, `external`, `querystring`, …), `URL`, `BACK`, `REDIRECT`, `EMAIL`, `PHONE`, `WHATSAPP`, `DATE`, `JSON`, `QUERY_STRING`, `SESSION`, `RANDOM`, `VARIABLE` (`set=` / read page variables), `SEPARATE`, `QUANTA` |
| list | `LIST_NODES` (children of a node; `tpl=` names a file in `src/modules/list/tpl/`, default `dir`; `sort`, `asc`, `reverse`, `limit`, `level`, `status`, `symlinks`, `separator`, `sortable`, …), `TREE`, `BLOCKS`, `COUNT_NODES`, `FILES`, `FILES_ADMIN`, `FILE_TABLE`, `UPLOAD_VIEW` |
| form | `FORM`, `INPUT` (`type=` selects the field class), `LABEL`, `OPTION`, `LIST_OPTIONS`, `LIST_VALUES`, `VALIDATION_ERROR`, `FORM_ITEM_REQUIRED_STAR`, and the field classes `FORM_ITEM_{STRING,TEXT,HIDDEN,SELECT,CHECKBOX,CHECKBOXES,RADIO,RADIOS,DATE,TIME,NUMBER,EMAIL,URL,TEL,PASSWORD,FILE,COLOR,RATING,ADDRESS,AUTOCOMPLETE,SUBMIT}` |
| localization | `TEXT` (`tag=` key; target is the default text), `TRANSLATE`, `TRANSLATE_LINKS`, `LANGUAGE`, `FALLBACK_LANGUAGE`, `LANGUAGE_SWITCHER` |
| user | `LOGIN`, `REGISTER`, `RESET_PASSWORD`, `USER_EDIT`, `USER_EDIT_OWN`, `USER_ATTRIBUTE` |
| file | `FILE`, `FILE_ATTRIBUTE`, `FILE_OPERATIONS`, `FILE_PREVIEW`, `FILE_QTAG_SUGGESTION`, `RESOLUTION_MESSAGE` |
| image | `IMG`, `IMG_THUMB`, `IMG_THUMB_URL`, `THUMBNAIL` (`640x480` style size flag) |
| media | `AUDIO`, `VIDEO`, `VIDEOS`, `LIST_ALBUM`, `LIST_SONGS` |
| shadow | `SHADOW`, `SHADOW_TAB`, `SHADOW_CONTENT`, `SHADOW_DESCRIPTION`, `SHADOW_CLOSE_BUTTON`, `SHADOW_RESPONSE` |
| block | `BLOCK` (embed a node), `WRAPPER` |
| grid | `GRID` (CSS-grid container), `GRID_CAROUSEL` |
| carousel | `CAROUSEL`, `FILE_CAROUSEL`, `ZOOM_CAROUSEL` |
| amp | `AMP_CAROUSEL`, `AMP_LINK`, `AMP_SIDEBAR`, `AMP_SIDEBAR_BUTTON`, `CANONICAL_LINK` |
| map | `MAP`, `LATITUDE`, `LONGITUDE` |
| fontawesome | `FAS_ICON`, `FAB_ICON`, `FA_ICON` (deprecated) |
| social | `SHARE_BUTTONS`, `YOUTUBE` |
| googledocs | `GENERATE_GOOGLE_DOC`, `READ_GOOGLE_DOC` |
| environment | `ENV`, `ENV_CONST` |
| qtags | `HTML_TAG` (generic element), `QTAG` (base class) |
| others | `PERMISSION` (access), `BLOG` (blog), `CAPTCHA` (captcha), `DOCTOR_TIMESTAMP` (doctor), `GALLERY` (gallery), `IMPORT_FILE` (import), `JUMPER` (jumper), `MAIL_FORM` (mail), `MENU` (menu; `links=` separated by `---`), `MESSAGES` (message), `META_DATA` (meta), `STATS` (stats) |

To find where a tag is used: `grep -rn "\[TAG\b\|{TAG\b" src profiles sites/<host>`.
