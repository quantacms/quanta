// Configuration for the documentation site.
//
// Everything the generator needs to know about *what* to build lives here:
// the site identity, the top navigation, and the map of repository Markdown
// files to the pages they become. `build.mjs` turns this into the generated
// half of `docs/`.
//
// The documentation pages are NOT copies: they are rendered straight from the
// Markdown that already lives in the repo, so the site can never drift from
// the docs a developer reads next to the code.

export const site = {
  title: 'Quanta CMS',
  tagline: 'A DB-free CMS and framework, built on the filesystem.',
  description:
    'Quanta is an open source, 100% filesystem-based CMS and PHP framework: ' +
    'no SQL, nodes as folders, Qtags as markup, and a Rust extension that ' +
    'keeps the whole node tree in shared memory.',
  repo: 'https://github.com/quantacms/quanta',
  branch: 'master',
  website: 'https://www.quanta.org',
  // Only used for absolute URLs (og:url, canonical). The site is not
  // published anywhere yet; every page links relatively, so nothing resolves
  // against this at runtime and the local preview is unaffected.
  baseUrl: 'https://quantacms.github.io/quanta/',
};

// Top navigation. `to` is either an output path inside the site or an
// external URL (anything with a scheme is left untouched). `match` lists the
// output prefixes that should light the link up; it defaults to `to`.
export const topNav = [
  {
    label: 'Documentation',
    to: 'overview.html',
    match: ['overview.html', 'introduction.html', 'qdb/'],
  },
  { label: 'qdb', to: 'qdb.html' },
  { label: 'quanta.org', to: site.website },
  { label: 'GitHub', to: site.repo },
];

// The documentation tree.
//
//   src   repo-relative Markdown file (the single source of truth)
//   out   output path inside `docs/`
//   title label in the sidebar (the page keeps its own H1 as the visible title)
//
// Relative links between these files (`usage.md`, `../qdb/docs/usage.md`)
// are rewritten to the corresponding site pages by the build; links pointing
// at repository files that are NOT in this list become GitHub links.
export const docsNav = [
  {
    section: 'Quanta',
    items: [
      { src: 'docs/README.md', out: 'overview.html', title: 'Overview' },
      { src: 'README.md', out: 'introduction.html', title: 'Introduction & install' },
    ],
  },
  {
    section: 'qdb',
    items: [
      { src: 'qdb/README.md', out: 'qdb/index.html', title: 'The extension' },
      { src: 'qdb/docs/usage.md', out: 'qdb/usage.html', title: 'Using qdb from PHP' },
      { src: 'qdb/docs/how-it-works.md', out: 'qdb/how-it-works.html', title: 'How qdb works' },
      { src: 'qdb/docs/api-contract.md', out: 'qdb/api-contract.html', title: 'API contract' },
    ],
  },
];

// Flattened view of every documentation page, in sidebar order — the build
// uses it for the link map and for prev/next navigation.
export const docsPages = docsNav.flatMap((s) =>
  s.items.map((item) => ({ ...item, section: s.section })),
);
