/* qdb browser.
 *
 * Talks only to this server's /api/* routes. Everything it renders comes from
 * the shared-memory segment, so what you see here is what a PHP worker would
 * be served -- not what is on disk.
 *
 * No innerHTML is used for anything derived from the index: node names, paths
 * and document contents are site data, and a browser that pasted them into
 * markup would be one crafted node name away from executing them.
 */
"use strict";

// A token supplied in the page URL has to ride along on every fetch -- you
// open this by pasting a link, where setting an Authorization header is not an
// option. Kept out of the DOM so it is not copied into anything rendered.
const TOKEN = new URLSearchParams(location.search).get("token");

const $ = (sel) => document.querySelector(sel);

/* Paths are RELATIVE, deliberately. The dashboard can be mounted under a URL
   prefix (an Ingress path, say), and a leading slash would leave that prefix
   and hit whatever else is served at the root. A relative path resolves
   against the directory of the current page, so it works at "/" and at "/qdb/"
   with no build step and nothing to configure in the page. The server's
   base-path redirect is what guarantees the trailing slash this relies on. */
function api(path, params) {
  const u = new URLSearchParams(params || {});
  if (TOKEN) u.set("token", TOKEN);
  const qs = u.toString();
  return fetch(path + (qs ? "?" + qs : ""), { headers: { Accept: "application/json" } })
    .then(async (r) => {
      const body = await r.json().catch(() => ({ error: "unreadable response" }));
      if (!r.ok) throw Object.assign(new Error(body.error || r.statusText), { body, status: r.status });
      return body;
    });
}

function el(tag, attrs, ...kids) {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k === "class") n.className = v;
    // Through the CSSOM, never as a style ATTRIBUTE. This page is served under
    // `style-src 'self'`, which blocks inline style attributes -- silently:
    // the attribute is set, the element has it, and it simply never applies.
    // CSSOM assignment is exempt from CSP by spec, so the strict policy stays
    // and the colours arrive. (Found the hard way: the treemap tiles, which
    // assign `.style.background` directly, were painted while the legend
    // swatches beside them, built here, came out transparent.)
    else if (k === "style") n.style.cssText = v;
    else if (k.startsWith("on")) n.addEventListener(k.slice(2), v);
    else n.setAttribute(k, v === true ? "" : v);
  }
  for (const kid of kids.flat()) {
    if (kid === null || kid === undefined || kid === false) continue;
    n.append(kid.nodeType ? kid : document.createTextNode(String(kid)));
  }
  return n;
}

function bytes(n) {
  if (n === null || n === undefined) return "—";
  const u = ["B", "KB", "MB", "GB", "TB"];
  let v = Number(n), i = 0;
  while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
  return (i === 0 ? v : v.toFixed(v < 10 ? 1 : 0)) + " " + u[i];
}

function count(n) {
  return n === null || n === undefined ? "—" : Number(n).toLocaleString("en-US");
}

function fileCount(files) {
  const n = (files || []).length;
  return n === 0 ? "no files" : n === 1 ? "1 file" : n + " files";
}

function when(unix) {
  if (!unix) return "—";
  return new Date(unix * 1000).toISOString().replace("T", " ").replace(/\..*/, "") + "Z";
}

/* ---------------------------------------------------------------- status */

function paintStatus(s) {
  const pill = $("#verdict");
  let label = "no segment", cls = "pill bad";
  if (s.segment && s.coherent) { label = "coherent"; cls = "pill ok"; }
  else if (s.segment) { label = "stale"; cls = "pill warn"; }
  pill.textContent = label;
  pill.className = cls;
  pill.title = s.segment
    ? (s.coherent
        ? "the daemon is publishing and the index is current"
        : "a segment is mapped but the daemon is not confirming it -- writes may be going to the fallback path")
    : "no segment is published; PHP is on the filesystem fallback path";
  $("#s-nodes").textContent = count(s.nodes);
  $("#s-links").textContent = count(s.links);
  $("#s-docs").textContent = bytes(s.doc_bytes);
  $("#s-epoch").textContent = s.epoch || "—";
}

function pollStatus() {
  api("api/summary").then(paintStatus).catch(() => {
    const pill = $("#verdict");
    pill.textContent = "unreachable";
    pill.className = "pill bad";
  });
}

/* ------------------------------------------------------------------ tree */

const expanded = new Set();
let selected = null;

/* A child row's three kinds are not decoration:
   - node    an indexed directory. Expandable, clickable.
   - symlink a link whose own filename is not a node name. Link rows are keyed
             by target, so this name is deliberately absent from the index and
             clicking it would 404. Its target shows under "Links to" on the
             father.
   - missing a real directory the daemon did NOT index. This is drift, and the
             reason to be looking at all. */
function nodeRow(n, depth) {
  const kind = n.kind || "node";
  const real = kind === "node";
  const kids = real ? n.children || 0 : 0;
  const open = expanded.has(n.name);

  const twisty = el("span", { class: "twisty" }, kids > 0 ? (open ? "▾" : "▸") : "");
  const row = el(
    "div",
    {
      class: "row" + (selected === n.name ? " sel" : ""),
      "data-name": real ? n.name : null,
      onclick: (e) => {
        if (!real) return;
        if (e.target === twisty && kids > 0) { toggle(n.name); return; }
        select(n.name);
      },
    },
    twisty,
    el("span", { class: "label" }, n.name),
    kind === "missing" && el("span", { class: "badge missing", title: "a child directory that is not in the index" }, "not indexed"),
    kind === "symlink" && el("span", { class: "badge link", title: "a symlink; its target is listed under Links to on the father" }, "symlink"),
    real && n.link && el("span", { class: "badge link", title: "reached through a symlink as well" }, "link"),
    n.corrupt && el("span", { class: "badge bad", title: "at least one document failed to parse" }, "corrupt"),
    // The neutral document's language is the empty string, so listing language
    // codes here prints a blank for the most common file there is. Count them
    // instead; the detail pane names them.
    real && el("span", { class: "meta" }, fileCount(n.files))
  );
  twisty.style.cursor = kids > 0 ? "pointer" : "default";

  const li = el("li", {}, row);
  if (open && kids > 0) {
    const ul = el("ul", {}, el("li", { class: "row" }, el("span", { class: "meta" }, "loading…")));
    li.append(ul);
    api("api/node", { name: n.name })
      .then((d) => { ul.replaceChildren(...d.children.map((c) => nodeRow(c, depth + 1))); })
      .catch((e) => { ul.replaceChildren(el("li", { class: "note" }, e.message)); });
  }
  return li;
}

function toggle(name) {
  if (expanded.has(name)) expanded.delete(name); else expanded.add(name);
  renderBrowser();
}

function renderTree() {
  const host = $("#browser");
  host.replaceChildren(el("div", { class: "note" }, "loading…"));
  api("api/roots")
    .then((d) => {
      $("#left-foot").textContent = `${count(d.total)} nodes in epoch ${d.epoch}`;
      if (!d.roots.length) {
        host.replaceChildren(el("div", { class: "note" }, "the segment holds no nodes"));
        return;
      }
      host.replaceChildren(el("ul", { class: "tree" }, ...d.roots.map((r) => nodeRow(r, 0))));
    })
    .catch(showBrowserError);
}

/* ------------------------------------------------------------------ list */

let listOffset = 0;
const LIST_LIMIT = 200;

function renderList() {
  const host = $("#browser");
  const q = $("#q").value.trim();
  host.replaceChildren(el("div", { class: "note" }, "scanning…"));
  api("api/list", { q, offset: listOffset, limit: LIST_LIMIT })
    .then((d) => {
      const rows = d.nodes.map((n) =>
        el(
          "tr",
          { class: selected === n.name ? "sel" : "", "data-name": n.name, onclick: () => select(n.name) },
          el("td", { class: "path" }, n.rel_path || n.name,
            n.corrupt && el("span", { class: "badge bad", title: "at least one document failed to parse" }, "corrupt")),
          el("td", {}, (n.files || []).join(", ") || "—"),
          el("td", { class: "num" }, bytes(n.doc_bytes)),
          el("td", { class: "num" }, count(n.children))
        )
      );
      host.replaceChildren(
        el(
          "table",
          { class: "list" },
          el("thead", {}, el("tr", {},
            el("th", {}, "path"), el("th", {}, "files"), el("th", {}, "size"), el("th", {}, "children"))),
          el("tbody", {}, ...rows)
        )
      );
      const shown = Math.min(d.matched, d.offset + d.nodes.length);
      // replaceChildren is a DOM call, not el(): a `false` from a short-circuit
      // would be appended as the text "false" rather than skipped.
      const foot = [
        el("span", {}, `${count(d.offset + (d.nodes.length ? 1 : 0))}–${count(shown)} of ${count(d.matched)}`
          + (q ? ` matching (${count(d.total)} total)` : "")),
      ];
      if (d.offset > 0) {
        foot.push(el("button", { class: "chip", onclick: () => { listOffset = Math.max(0, listOffset - LIST_LIMIT); renderList(); } }, "prev"));
      }
      if (shown < d.matched) {
        foot.push(el("button", { class: "chip", onclick: () => { listOffset += LIST_LIMIT; renderList(); } }, "next"));
      }
      $("#left-foot").replaceChildren(...foot);
    })
    .catch(showBrowserError);
}

function showBrowserError(e) {
  const detail = (e.body && e.body.detail) || e.message;
  $("#browser").replaceChildren(el("div", { class: "banner" }, detail));
  $("#left-foot").textContent = "";
}

let mode = "tree";

function renderBrowser() {
  if (mode === "tree") renderTree(); else renderList();
}

/* ---------------------------------------------------------------- detail */

/* Render a decoded document as coloured, indented text. Built out of text
   nodes and spans, never markup -- see the file header. */
function jsonDom(v, indent, out) {
  const pad = "  ".repeat(indent);
  if (v === null) { out.append(el("span", { class: "z" }, "null")); return; }
  if (Array.isArray(v)) {
    if (!v.length) { out.append("[]"); return; }
    out.append("[\n");
    v.forEach((item, i) => {
      out.append(pad + "  ");
      jsonDom(item, indent + 1, out);
      out.append(i < v.length - 1 ? ",\n" : "\n");
    });
    out.append(pad + "]");
    return;
  }
  switch (typeof v) {
    case "object": {
      const keys = Object.keys(v);
      if (!keys.length) { out.append("{}"); return; }
      out.append("{\n");
      keys.forEach((k, i) => {
        out.append(pad + "  ");
        out.append(el("span", { class: "k" }, JSON.stringify(k)));
        out.append(": ");
        jsonDom(v[k], indent + 1, out);
        out.append(i < keys.length - 1 ? ",\n" : "\n");
      });
      out.append(pad + "}");
      return;
    }
    case "string": out.append(el("span", { class: "s" }, JSON.stringify(v))); return;
    case "number": out.append(el("span", { class: "n" }, String(v))); return;
    case "boolean": out.append(el("span", { class: "b" }, String(v))); return;
    default: out.append(String(v));
  }
}

/* Why a document is not shown, in the terms the segment uses. Each of these is
   a real state the index can be in, not an error to paper over. */
const SOURCE_NOTE = {
  corrupt: "the daemon could not parse this file, so nothing is resident for it. PHP raises CORRUPT_JSON for this language.",
  not_resident: "neither an image nor raw bytes are in the segment (the document is over quanta_db.image_max_doc_kb, or images are off). A read of it goes to the file.",
  unreadable: "the document image is in the segment but did not decode -- this is a bug worth reporting, with the node name and epoch.",
  too_large: "the document is too large to render here. It is in the segment and PHP serves it normally.",
};

function docPanel(node, lang) {
  const body = el("div", { class: "note" }, "loading…");
  api("api/doc", { name: node, lang: lang.lang })
    .then((d) => {
      if (d.source === "raw" || d.source === "image") {
        const pre = el("pre", { class: "json" });
        jsonDom(d.doc, 0, pre);
        body.replaceWith(pre);
      } else if (d.source === "raw_text") {
        body.replaceWith(el("pre", { class: "json" }, d.text));
      } else {
        body.replaceWith(el("div", { class: "banner" }, SOURCE_NOTE[d.source] || d.detail || d.source));
      }
    })
    .catch((e) => { body.replaceWith(el("div", { class: "banner" }, e.message)); });
  return body;
}

/* Where this document's content actually lives. "corrupt" is not a third kind
   of residency but it has to come first: the daemon keeps nothing resident for
   a file it could not parse, so calling that "on disk only" would read as a
   size decision rather than a parse failure. */
function residency(l) {
  if (l.corrupt) return "not parsed";
  if (l.imaged) return "imaged";
  if (l.raw) return "raw json";
  return "on disk only";
}

function fileBlock(node, l) {
  const det = el(
    "details",
    { class: "file" },
    el(
      "summary",
      {},
      el("span", {}, l.file),
      l.corrupt && el("span", { class: "badge bad" }, "corrupt"),
      !l.imaged && !l.raw && !l.corrupt && el("span", { class: "badge missing" }, "not in segment"),
      el("span", { class: "meta" },
        `${bytes(l.size)} · ${when(l.mtime)} · ${residency(l)}`)
    )
  );
  let loaded = false;
  det.addEventListener("toggle", () => {
    if (det.open && !loaded) { loaded = true; det.append(docPanel(node, l)); }
  });
  return det;
}

function renderDetail(d) {
  const host = $("#detail");
  host.className = "";
  const KIND_TITLE = {
    symlink: "a symlink; the node it points at is listed under Links to",
    missing: "a child directory that is not in the index",
  };
  const chips = (arr, empty) =>
    arr.length
      ? el("div", { class: "chips" }, ...arr.map((c) => {
          const kind = c.kind || "node";
          return el("button", {
            class: "chip" + (kind === "node" ? "" : " missing"),
            title: KIND_TITLE[kind] || c.rel_path,
            onclick: () => { if (kind === "node") select(c.name); },
          }, c.name, c.link ? el("small", {}, " ↗") : null);
        }))
      : el("div", { class: "note" }, empty);

  host.replaceChildren(
    el("h1", { class: "node" }, d.name),
    el("div", { class: "crumbs" }, d.rel_path),
    el(
      "dl",
      { class: "grid" },
      el("dt", {}, "father"),
      el("dd", {}, d.father
        ? el("button", { class: "chip", onclick: () => select(d.father) }, d.father)
        : "— (no father: a tree root, or an orphan)"),
      el("dt", {}, "generation"), el("dd", {}, count(d.generation)),
      el("dt", {}, "modified"), el("dd", {}, when(d.mtime)),
      el("dt", {}, "documents"), el("dd", {}, count(d.langs.length))
    ),
    el("h2", {}, `Files (${d.langs.length})`),
    d.langs.length
      ? el("div", { class: "files" }, ...d.langs.map((l) => fileBlock(d.name, l)))
      : el("div", { class: "note" }, "this node holds no documents — it is a container only"),
    el("h2", {}, `Children (${d.children.length})`),
    chips(d.children, "no children"),
    el("h2", {}, `Links to (${d.links_out.length})`),
    chips(d.links_out, "this node links nowhere"),
    el("h2", {}, `Linked from (${d.inlinks.length})`),
    chips(d.inlinks, "nothing links here")
  );
}

function select(name) {
  if (!name) return;
  location.hash = "#/node/" + encodeURIComponent(name);
}

function showNode(name) {
  selected = name;
  // Keep the tree in step with a selection made from the list or a chip.
  document.querySelectorAll(".row.sel, tr.sel").forEach((n) => n.classList.remove("sel"));
  document.querySelectorAll(`[data-name="${CSS.escape(name)}"]`).forEach((n) => n.classList.add("sel"));
  const host = $("#detail");
  host.className = "";
  host.replaceChildren(el("div", { class: "note" }, "loading…"));
  api("api/node", { name })
    .then(renderDetail)
    .catch((e) => {
      host.className = "empty";
      host.replaceChildren(el("div", { class: "banner" }, (e.body && e.body.detail) || e.message));
    });
}

/* ----------------------------------------------------------------- stats */

const STAT_SECTIONS = [
  ["Daemon", [
    ["daemon pid", "daemon_pid", count], ["active epoch", "data_epoch", count],
    ["watch epoch", "watch_epoch", count], ["coherent", "watch_coherent", (v) => (v ? "yes" : "no")],
    ["heartbeat", "watch_heartbeat_unix", when], ["events applied", "watch_events", count],
    ["resyncs", "watch_resyncs", count], ["resyncs/min", "resyncs_per_min", (v) => Number(v).toFixed(2)],
    ["uds notifies", "uds_notifies", count], ["uds failures", "uds_failures", count],
  ]],
  ["Storage", [
    ["nodes", "segment.nodes", count], ["links", "segment.links", count],
    ["tombstones", "segment.tombstones", count], ["document bytes", "segment.doc_bytes", bytes],
    ["image bytes", "segment.img_bytes", bytes], ["raw bytes", "segment.raw_bytes", bytes],
    ["strings", "segment.str_count", count], ["string bytes", "segment.str_bytes", bytes],
    ["segment size", "segment.seg_size", bytes], ["arena used", "segment.arena_used", bytes],
    ["dead bytes", "segment.dead_bytes", bytes], ["used %", "segment_used_pct", (v) => Number(v).toFixed(1) + "%"],
    ["dead %", "dead_pct", (v) => Number(v).toFixed(1) + "%"],
    ["tmpfs total", "shm.total_bytes", bytes], ["tmpfs available", "shm.avail_bytes", bytes],
    ["a compaction needs", "shm.compaction_needs_bytes", bytes],
  ]],
  ["Reads", [
    ["reads", "reads", count], ["avg read", "avg_read_ms", (v) => Number(v).toFixed(3) + " ms"],
    ["peak read", "read_ns_max", (v) => (Number(v) / 1e6).toFixed(3) + " ms"],
    ["from index", "index_serves", count], ["from file", "file_reads", count],
    ["from fallback", "fallback_reads", count], ["bytes read", "bytes_read", bytes],
    ["cache hits", "cache_hits", count], ["cache misses", "cache_misses", count],
    ["negative hits", "neg_hits", count], ["node misses", "node_misses", count],
    ["stale hits", "stale_hits", count], ["shm remaps", "shm_remaps", count],
  ]],
  ["Queries", [
    ["children", "queries.children", count], ["find", "queries.find", count],
    ["count", "queries.count", count], ["links", "queries.links", count],
    ["link", "queries.link", count], ["unlink", "queries.unlink", count],
  ]],
  ["Writes", [
    ["writes", "writes", count], ["avg write", "avg_write_ms", (v) => Number(v).toFixed(3) + " ms"],
    ["peak write", "write_ns_max", (v) => (Number(v) / 1e6).toFixed(3) + " ms"],
    ["bytes written", "bytes_written", bytes], ["deletes", "deletes", count],
    ["lock acquires", "lock_acquires", count], ["lock timeouts", "lock_timeouts", count],
    ["io errors", "io_errors", count], ["corrupt json", "corrupt_json", count],
  ]],
];

const dig = (o, path) => path.split(".").reduce((v, k) => (v === undefined || v === null ? v : v[k]), o);

function renderStats(d) {
  const host = $("#stats");
  const verdict = el("span", { class: "pill " + ({ healthy: "ok", degraded: "warn" }[d.health] || "bad") }, d.health);
  const kids = [el("div", { class: "sect" }, el("h2", {}, "Verdict"), verdict)];
  if (!d.arena_ok) {
    kids.push(el("div", { class: "banner" },
      "No metrics arena: quanta_db.metrics is off, or no PHP worker has run for this root yet. "
      + "Counters below are zero for that reason, not because nothing happened."));
  }
  for (const [title, rows] of STAT_SECTIONS) {
    kids.push(el("div", { class: "sect" }, el("h2", {}, title),
      el("div", { class: "kv" }, ...rows.map(([label, path, fmt]) => {
        const v = dig(d, path);
        return el("div", {}, el("span", {}, label),
          el("span", {}, v === undefined || v === null ? "—" : fmt(v)));
      }))));
  }
  host.replaceChildren(...kids);
}

function loadStats() {
  api("api/stats").then(renderStats).catch((e) => {
    $("#stats").replaceChildren(el("div", { class: "banner" }, e.message));
  });
}

/* ----------------------------------------------------------------- sizes */

/* Area already encodes magnitude, so the ramp encodes RANK: five ordinal steps
   of ONE hue, running quiet-for-large and loud-for-small (see app.css). Rank is
   derived from size, so the two agree monotonically and neither contradicts the
   other. Beyond five children the ranks fold onto the same five steps rather
   than inventing a sixth colour -- past ~7 classes adjacent ones blur anyway,
   and the ranked list is what carries exact identity. */
const TM_STEPS = 5;
const tileStep = (rank, n) =>
  n <= 1 ? 1 : Math.min(TM_STEPS, 1 + Math.round((rank / (n - 1)) * (TM_STEPS - 1)));
const tileColor = (rank, n) => `var(--tm-${tileStep(rank, n)})`;
/* Steps 1-2 sit on the quiet end of the ramp, 3-5 on the loud one, and which
   ink reads on which flips with the theme -- so the class picks the token and
   the token picks the colour, rather than hard-coding either. */
const tileInk = (rank, n) => (tileStep(rank, n) >= 3 ? " small" : "");

/* Squarified treemap (Bruls, Huizing & van Wijk). Laying children out in plain
   rows makes slivers of everything after the first; squarifying keeps tiles
   near square, which is what makes two areas comparable by eye at all. */
function squarify(values, x, y, w, h) {
  const out = [];
  const total = values.reduce((a, b) => a + b, 0);
  if (total <= 0 || w <= 0 || h <= 0) return values.map(() => null);

  let items = values.map((v, i) => ({ v: (v / total) * w * h, i }));
  let row = [];
  const worst = (row, len) => {
    if (!row.length || len <= 0) return Infinity;
    const sum = row.reduce((a, r) => a + r.v, 0);
    const max = Math.max(...row.map((r) => r.v));
    const min = Math.min(...row.map((r) => r.v));
    const s2 = sum * sum, l2 = len * len;
    return Math.max((l2 * max) / s2, s2 / (l2 * min));
  };

  const place = (row, len, horizontal) => {
    const sum = row.reduce((a, r) => a + r.v, 0);
    const thick = sum / len;
    let off = horizontal ? x : y;
    for (const r of row) {
      const side = r.v / thick;
      out[r.i] = horizontal
        ? { x: off, y, w: side, h: thick }
        : { x, y: off, w: thick, h: side };
      off += side;
    }
    if (horizontal) { y += thick; h -= thick; } else { x += thick; w -= thick; }
  };

  while (items.length) {
    const horizontal = w >= h;
    const len = horizontal ? w : h;
    const next = items[0];
    if (!row.length || worst([...row, next], len) <= worst(row, len)) {
      row.push(next);
      items = items.slice(1);
    } else {
      place(row, len, horizontal);
      row = [];
    }
    if (!items.length && row.length) {
      place(row, horizontal ? w : h, horizontal);
      row = [];
    }
  }
  return out;
}

const METRIC_NOTE = {
  docs: "Documents as they are on disk, summed over every language. This is how much content sits under each node.",
  segment: "What each subtree occupies in the shared-memory segment: record header, name, path, father, children, inlinks, and every language's raw bytes and pre-decoded image. This is the number /dev/shm has to hold — two of it during a compaction.",
};

let sizeMetric = "docs";
let sizeName = null;
let sizeData = null;

function tip(html, ev) {
  let t = $("#tip");
  if (!t) { t = el("div", { id: "tip" }); document.body.append(t); }
  t.replaceChildren(...html);
  t.hidden = false;
  // Flip before the viewport edge rather than after: a tooltip that opens
  // offscreen is the same as no tooltip.
  const r = t.getBoundingClientRect();
  const x = ev.clientX + 14 + r.width > window.innerWidth ? ev.clientX - r.width - 14 : ev.clientX + 14;
  const y = ev.clientY + 14 + r.height > window.innerHeight ? ev.clientY - r.height - 14 : ev.clientY + 14;
  t.style.left = x + "px";
  t.style.top = y + "px";
}

function hideTip() {
  const t = $("#tip");
  if (t) t.hidden = true;
}

function tipFor(c, total) {
  return [
    el("span", { class: "tip-name" }, c.other ? c.name : c.rel_path || c.name),
    el("div", { class: "tip-row" }, `${bytes(c.total)} · ${pct(c.total, total)} of this level`),
    el("div", { class: "tip-row" }, `${count(c.nodes)} node${c.nodes === 1 ? "" : "s"}`),
    c.own > 0 && el("div", { class: "tip-row" }, `${bytes(c.own)} of it this node's own`),
    el("div", { class: "tip-row" },
      c.other ? "too small to draw separately — see the list" : "click to open · shift-click to inspect"),
  ].filter(Boolean);
}

const pct = (v, total) => (total > 0 ? ((v / total) * 100).toFixed(v / total < 0.01 ? 2 : 1) + "%" : "—");

/* Most tiles a map is allowed to draw. Past this the tiles are slivers and the
   shape the map exists to show is gone, so the remainder folds into one neutral
   tile whose AREA IS THE TRUE SUM of what it holds — the picture still adds up
   to 100%, it just stops pretending the tail is individually readable.

   Folding is by COUNT ONLY, deliberately. A share threshold looks reasonable
   and is wrong on the case that matters: a level of 400 similar children has
   nothing above 0.4%, so a threshold folds all but one and the map says
   "one thing and a mystery" about a level whose real answer is "400 things,
   all the same size". The count rule keeps the head visible either way, and
   `spreadNote` says in words what a flat map cannot. */
const FOLD_MAX = 24;

function foldChildren(d) {
  const rest = d.rest || { count: 0, total: 0, nodes: 0 };
  const kept = d.children.slice(0, FOLD_MAX);
  const tail = d.children.slice(FOLD_MAX);
  const folded = {
    total: rest.total + tail.reduce((a, c) => a + c.total, 0),
    nodes: rest.nodes + tail.reduce((a, c) => a + c.nodes, 0),
    count: rest.count + tail.length,
  };
  return { kept, folded: folded.count > 0 ? folded : null };
}

/* When no single child stands out, the treemap has no shape to show and the
   honest thing is to say so rather than let a wall of equal tiles imply one. */
function spreadNote(d) {
  const kids = d.children;
  if (!kids.length || d.total <= 0) return "";
  const top = kids[0].total / d.total;
  const all = (d.rest && d.rest.count ? d.rest.count : 0) + kids.length;
  if (top >= 0.15 || all < 8) return "";
  return `Weight here is spread evenly: ${count(all)} children, the largest only `
    + `${pct(kids[0].total, d.total)}. Nothing in particular is heavy — the level itself is.`;
}

function drawTreemap(d) {
  const host = $("#treemap");
  host.replaceChildren();
  const { kept, folded } = foldChildren(d);
  const kids = folded
    ? kept.concat([{ name: `${count(folded.count)} smaller`, total: folded.total, nodes: folded.nodes, own: 0, other: true }])
    : kept;
  if (!kids.length) {
    host.append(el("div", { class: "note" },
      d.total > 0
        ? "no children — all of this node's weight is its own documents"
        : "nothing under this node"));
    return;
  }
  const w = host.clientWidth, h = host.clientHeight;
  const rects = squarify(kids.map((c) => c.total), 0, 0, w, h);

  kids.forEach((c, i) => {
    const r = rects[i];
    if (!r || r.w < 1 || r.h < 1) return;
    // Two tiers, because a half-drawn label is worse than none: a tile wide
    // enough for a name is not necessarily tall enough for a name AND a size,
    // and clipping the second line mid-word ("776 B ·") reads as a rendering
    // fault rather than as a small tile.
    const showName = r.w >= 46 && r.h >= 18;
    const showSize = r.w >= 78 && r.h >= 32;
    const tiny = !showName;
    // The 2px inset IS the surface gap between fills — subtracted from the
    // rect rather than drawn as a border, so no line appears that the data
    // does not have.
    const tile = el("div", {
      class: "tile" + (tiny ? " tiny" : "") + tileInk(i, kids.length),
      tabindex: tiny ? null : "0",
      onclick: (ev) => {
        if (c.other) return;
        if (ev.shiftKey) select(c.name); else openSize(c.name);
      },
      onmousemove: (ev) => tip(tipFor(c, d.total), ev),
      onmouseleave: hideTip,
      onkeydown: (ev) => { if (ev.key === "Enter") openSize(c.name); },
      "data-tile": c.name,
    });
    tile.style.left = r.x + 1 + "px";
    tile.style.top = r.y + 1 + "px";
    tile.style.width = Math.max(0, r.w - 2) + "px";
    tile.style.height = Math.max(0, r.h - 2) + "px";
    // The fold tile is deliberately OUTSIDE the ramp: it is not a rank, it is
    // an absence of detail, and giving it a ramp step would read as "the
    // smallest child" rather than "everything else".
    tile.style.background = c.other ? "var(--tm-other)" : tileColor(i, kids.length);
    if (c.other) tile.classList.add("other");
    if (showName) tile.append(el("span", { class: "t-name" }, c.name));
    if (showSize) {
      tile.append(el("span", { class: "t-size" }, `${bytes(c.total)} · ${pct(c.total, d.total)}`));
    }
    host.append(tile);
  });
}

/* The list is the treemap's legend AND its table view: every row pairs the
   tile's colour with the name and the exact number, so identity is never
   colour alone and a slice too small to label is still readable here. */
function drawRanked(d) {
  const host = $("#ranked");
  const { kept, folded } = foldChildren(d);
  // Swatches match the map exactly: a row that has a tile gets that tile's
  // ramp step, a row inside the fold gets the fold's neutral. Otherwise the
  // legend would claim a colour the map never drew.
  const tileCount = kept.length + (folded ? 1 : 0);
  const rows = d.children.map((c, i) =>
    el(
      "tr",
      {
        onclick: () => openSize(c.name),
        onmouseenter: () => highlightTile(c.name, true),
        onmouseleave: () => highlightTile(c.name, false),
        title: c.rel_path,
      },
      el("td", { class: "r-name" },
        el("span", {
          class: "swatch",
          style: `background:${i < kept.length ? tileColor(i, tileCount) : "var(--tm-other)"}`,
        }),
        c.name),
      el("td", { class: "r-num" }, bytes(c.total)),
      el("td", { class: "r-pct" }, pct(c.total, d.total))
    )
  );

  const restCount = (d.rest && d.rest.count) || 0;
  if (restCount > 0) {
    // The tail the server aggregated. Not a row you can click: there is no one
    // node behind it.
    rows.push(el("tr", { class: "total-row", title: "not listed individually" },
      el("td", {}, el("span", { class: "swatch", style: "background:var(--tm-other)" }),
        `${count(restCount)} more`),
      el("td", { class: "r-num" }, bytes(d.rest.total)),
      el("td", { class: "r-pct" }, pct(d.rest.total, d.total))));
  }

  const shown = d.children.length + (restCount > 0 ? restCount : 0);
  const head = el("tr", {},
    el("th", {}, `${count(shown)} child${shown === 1 ? "" : "ren"}`),
    el("th", { class: "r-num" }, "size"),
    el("th", { class: "r-pct" }, "share"));

  const totalRow = el("tr", { class: "total-row" },
    el("td", {}, `${count(d.nodes)} nodes total`),
    el("td", { class: "r-num" }, el("b", {}, bytes(d.total))),
    el("td", { class: "r-pct" }, ""));

  const ownRow = d.own > 0
    ? el("tr", { class: "total-row", title: "documents on this node itself, not in any child" },
        el("td", {}, "this node's own"),
        el("td", { class: "r-num" }, bytes(d.own)),
        el("td", { class: "r-pct" }, pct(d.own, d.total)))
    : null;

  host.replaceChildren(
    el("table", {},
      el("colgroup", {}, el("col", { class: "c-name" }), el("col", { class: "c-num" }), el("col", { class: "c-pct" })),
      el("thead", {}, head),
      el("tbody", {}, totalRow, ownRow, ...rows))
  );
}

/* Hovering a list row lights its tile, so a row too small to find on the map
   still points at it. Tiles below the label threshold are skipped during the
   draw, so the two collections are not index-aligned -- look the tile up by the
   name the row carries. */
function highlightTile(name, on) {
  const t = $("#treemap").querySelector(`[data-tile="${CSS.escape(name)}"]`);
  if (t) t.style.outlineColor = on ? "var(--fg)" : "transparent";
}

function drawCrumbs(d) {
  const nav = $("#crumbs");
  const parts = [el("button", { onclick: () => openSize(null) }, "everything")];
  for (const c of d.breadcrumb) {
    parts.push(el("span", { class: "sep" }, "/"));
    parts.push(el("button", { onclick: () => openSize(c.name), title: c.rel_path }, c.name));
  }
  if (d.name) {
    parts.push(el("span", { class: "sep" }, "/"));
    parts.push(el("span", { class: "here", title: d.rel_path }, d.name));
  }
  nav.replaceChildren(...parts);
}

function renderSizes(d) {
  sizeData = d;
  drawCrumbs(d);
  drawTreemap(d);
  drawRanked(d);
  const spread = spreadNote(d);
  $("#metric-note").textContent = spread ? `${spread}  ${METRIC_NOTE[d.metric] || ""}` : METRIC_NOTE[d.metric] || "";
}

function loadSizes() {
  const host = $("#treemap");
  host.replaceChildren(el("div", { class: "note" }, "measuring…"));
  const params = { metric: sizeMetric };
  if (sizeName) params.name = sizeName;
  api("api/sizes", params)
    .then(renderSizes)
    .catch((e) => {
      host.replaceChildren(el("div", { class: "banner" }, (e.body && e.body.detail) || e.message));
      $("#ranked").replaceChildren();
    });
}

function openSize(name) {
  hideTip();
  location.hash = name ? "#/sizes/" + encodeURIComponent(name) : "#/sizes";
}

/* --------------------------------------------------------------- routing */

let statsTimer = null;

function route() {
  const h = location.hash || "#/files";
  const node = h.match(/^#\/node\/(.*)$/);
  const sizes = h.match(/^#\/sizes(?:\/(.*))?$/);
  const stats = h === "#/stats";
  const view = stats ? "stats" : sizes ? "sizes" : "files";

  $("#files-view").hidden = view !== "files";
  $("#sizes-view").hidden = view !== "sizes";
  $("#stats-view").hidden = view !== "stats";
  document.querySelectorAll("#bar nav a").forEach((a) =>
    a.classList.toggle("on", (a.dataset.view || "files") === view));

  clearInterval(statsTimer);
  statsTimer = null;
  hideTip();

  if (view === "stats") {
    loadStats();
    statsTimer = setInterval(loadStats, 5000);
    return;
  }
  if (view === "sizes") {
    sizeName = sizes[1] ? decodeURIComponent(sizes[1]) : null;
    loadSizes();
    return;
  }
  if (node) showNode(decodeURIComponent(node[1]));
}

function init() {
  $("#mode").addEventListener("click", (e) => {
    const b = e.target.closest("button[data-mode]");
    if (!b) return;
    mode = b.dataset.mode;
    listOffset = 0;
    $("#mode").querySelectorAll("button").forEach((x) => x.classList.toggle("on", x === b));
    renderBrowser();
  });

  let debounce;
  $("#q").addEventListener("input", () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
      // A filter is a question about the whole index, and the tree can only
      // answer it for what happens to be expanded -- so typing switches views.
      if (mode !== "list" && $("#q").value.trim()) {
        mode = "list";
        $("#mode").querySelectorAll("button").forEach((x) => x.classList.toggle("on", x.dataset.mode === "list"));
      }
      listOffset = 0;
      renderBrowser();
    }, 180);
  });

  $("#metric").addEventListener("click", (e) => {
    const b = e.target.closest("button[data-metric]");
    if (!b || b.dataset.metric === sizeMetric) return;
    sizeMetric = b.dataset.metric;
    $("#metric").querySelectorAll("button").forEach((x) => x.classList.toggle("on", x === b));
    loadSizes();
  });

  // The treemap is laid out in pixels, so it has to be laid out again when the
  // pixels change. Debounced: a drag fires this continuously, and each redraw
  // rebuilds every tile.
  let resizeTimer;
  window.addEventListener("resize", () => {
    if ($("#sizes-view").hidden || !sizeData) return;
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => drawTreemap(sizeData), 120);
  });

  window.addEventListener("hashchange", route);
  pollStatus();
  setInterval(pollStatus, 5000);
  renderBrowser();
  route();
}

init();
