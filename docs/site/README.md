# The Quanta documentation site (`docs/site/`)

The source of the site under `docs/`: a landing page, an animated qdb
walkthrough, and the project documentation rendered from the repository's own
Markdown.

```bash
npm install          # once — the build needs one dev dependency (marked)
npm run docs:serve   # build + preview at http://localhost:4000 (rebuilds on change)
npm run docs:build   # build only, into docs/
npm run docs:check   # verify that every internal link and anchor resolves
```

The build writes into `docs/` — the same directory this generator lives in —
and the result is committed. See [The output lives in `docs/`](#the-output-lives-in-docs)
and [Publishing](#publishing) below.

## How it is put together

| Path | |
|---|---|
| `docs/site/config.mjs` | Site identity, top navigation, and the map of repository Markdown files to documentation pages. **This is the file you edit to add a page.** |
| `docs/site/build.mjs` | The generator: Markdown → HTML, link rewriting, sidebar, table of contents, prev/next. |
| `docs/site/layout.mjs` | The HTML shell shared by every page, and the relative-URL helper. |
| `docs/site/pages/*.html` | Hand-written pages (the landing page, the qdb walkthrough, 404). A page's first `<!-- key: value -->` lines are its metadata. |
| `docs/site/assets/` | Stylesheets, scripts, favicon — copied verbatim. |
| `docs/site/serve.mjs` | Local preview server with rebuild-on-change. |
| `docs/site/check-links.mjs` | Post-build link and anchor check. |

Two properties are worth keeping if you change any of this:

* **The docs are not copied.** Every documentation page is rendered from the
  Markdown that already lives in the repository, so the site cannot drift from
  what a developer reads next to the code. Relative links between those files
  are rewritten to site pages when the target is published, and to GitHub when
  it is not.
* **Every URL is relative.** There is no base path to configure, so the same
  output works at `http://localhost:4000/`, from a subdirectory, or from the
  root of a domain.

## The output lives in `docs/`

`docs/` holds three kinds of file, and the build is careful about the
difference:

| | |
|---|---|
| `docs/site/**` | the generator — its own source, never touched by a build |
| `docs/README.md`, and the other `.md` files | documentation **sources**, some of which are rendered into pages |
| `docs/*.html`, `docs/qdb/`, `docs/assets/`, `docs/.nojekyll` | **generated**, and committed |

Because the output directory contains its own input, the build cannot simply
delete it and start over. Instead every file it writes is recorded in
`docs/.build-manifest`, and the next build deletes exactly that list (and any
directory it empties) before regenerating. A path in the manifest that would
escape `docs/` is ignored rather than followed. Nothing that is not on that
list is ever removed, so a Markdown file dropped into `docs/` is safe.

The practical consequence: **do not hand-edit the generated files** — edit
`docs/site/` and rebuild. `git status` after a build shows the diff of what
changed, which is the review you want before committing.

## Adding a hand-written page

Drop an HTML fragment (body content only) into `docs/site/pages/`. The leading
`<!-- key: value -->` comments are its metadata:

```html
<!-- title: qdb, step by step -->
<!-- description: … used for <meta> and the social card -->
<!-- variant: home -->
<!-- assets: qdb.css, qdb.js -->
```

`assets` lists extra files from `docs/site/assets/` that only this page needs: `.css`
is linked in the head, `.js` is added deferred at the end of the body. Use
`{{link:overview.html}}` anywhere in the body for a URL relative to this page.

The qdb walkthrough (`docs/site/pages/qdb.html` with
`assets/qdb.{css,js}`) is the worked example. Its player is progressive
enhancement: the markup is a diagram, three code listings and three numbered
lists of what happens, and `qdb.js` turns those lists into steps that light
up the diagram, highlight lines of code and switch the data panel. Each `<li>`
carries the whole step definition —

```html
<li class="fdb__step" data-nodes="shm php" data-edge="map"
    data-lines="4" data-panel="slots" data-mark="5">
```

— so a step is edited, reordered or added in the HTML alone; the script never
needs to know what the story is.

## Adding a documentation page

Add the Markdown file to the repository as usual, then add one entry to
`docsNav` in `docs/site/config.mjs`:

```js
{ src: 'qdb/docs/my-page.md', out: 'qdb/my-page.html', title: 'My page' }
```

It appears in the sidebar and in the prev/next pager, and links to it from other
documents start resolving to it. A listed file that does not exist is skipped
with a warning rather than breaking the build.

## Publishing

Nothing publishes this site today; it is read locally with `npm run docs:serve`.

The layout is the one GitHub Pages expects, though, so turning it on later is a
settings change rather than a file move: **Settings → Pages → Build and
deployment → Source → "Deploy from a branch" → `master` / `/docs`**. GitHub
serves `docs/` as the site root, which is exactly what the local server does,
and the committed output is what gets served — no build step, no workflow. (On
a private repository that option needs GitHub Enterprise; on a public one it is
available to everyone.)

Two details are already in place for that day: `.nojekyll` is written on every
build, so the output is served verbatim instead of being run through Jekyll,
and every URL is relative, so `/quanta/` needs no configuration. A custom
domain additionally needs a `CNAME` file written next to `.nojekyll` in
`build.mjs`.
