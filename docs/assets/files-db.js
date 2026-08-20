// The Files-DB walkthrough player.
//
// The page works without this file: the three tracks are then simply three
// numbered lists of what happens, next to the diagram and the code they
// describe. What this adds is the sequencing — one step at a time, lighting up
// the part of the diagram it talks about, the lines of code it runs, and the
// panel that shows the data at that moment.

(function () {
  'use strict';

  var fdb = document.querySelector('[data-fdb]');
  if (!fdb) return;

  var HOLD = 5600;                 // ms a step stays on screen while playing
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var tabs = Array.prototype.slice.call(fdb.querySelectorAll('[data-tab]'));
  var order = tabs.map(function (t) { return t.getAttribute('data-tab'); });
  var lists = {};
  var steps = {};
  var codes = {};

  order.forEach(function (name) {
    var list = fdb.querySelector('[data-steps="' + name + '"]');
    lists[name] = list;
    steps[name] = Array.prototype.slice.call(list.querySelectorAll('.fdb__step'));
    codes[name] = fdb.querySelector('[data-code-track="' + name + '"]');
  });

  var panels = Array.prototype.slice.call(fdb.querySelectorAll('[data-panel]'));
  var nodes = Array.prototype.slice.call(fdb.querySelectorAll('[data-node]'));
  var edges = Array.prototype.slice.call(fdb.querySelectorAll('[data-edge]'));
  var slotRows = Array.prototype.slice.call(fdb.querySelectorAll('[data-slot]'));

  var controls = fdb.querySelector('[data-controls]');
  var dotsBox = controls.querySelector('[data-dots]');
  var counter = controls.querySelector('[data-count]');
  var playButton = controls.querySelector('[data-play]');

  var track = order[0];
  var index = 0;
  var timer = null;
  var playing = false;
  var userPaused = false;

  /* --- one-off DOM preparation ------------------------------------- */

  // Split each listing into addressable lines. This is safe because no tag in
  // those listings ever spans a line break.
  fdb.querySelectorAll('[data-code]').forEach(function (code) {
    var lines = code.innerHTML.replace(/\n+$/, '').split('\n');
    code.innerHTML = lines
      .map(function (line, i) {
        return '<span class="fdb-line" data-line="' + (i + 1) + '">' +
          (line === '' ? '&#8203;' : line) + '</span>';
      })
      .join('');
  });

  var dots = [];
  function buildDots() {
    dotsBox.innerHTML = '';
    dots = steps[track].map(function (step, i) {
      var dot = document.createElement('button');
      dot.type = 'button';
      dot.className = 'fdb__dot';
      dot.setAttribute('aria-label', 'Step ' + (i + 1));
      dot.addEventListener('click', function () { stop(true); go(i); });
      dotsBox.appendChild(dot);
      return dot;
    });
  }

  /* --- rendering ---------------------------------------------------- */

  /** "1,4" and "4-6" both mean a set of line numbers. */
  function lineSet(value) {
    var set = {};
    (value || '').split(',').forEach(function (part) {
      var range = part.trim().split('-');
      var from = parseInt(range[0], 10);
      var to = parseInt(range.length > 1 ? range[1] : range[0], 10);
      if (isNaN(from) || isNaN(to)) return;
      for (var n = from; n <= to; n++) set[n] = true;
    });
    return set;
  }

  function render() {
    var step = steps[track][index];
    var lit = ' ' + (step.getAttribute('data-nodes') || '') + ' ';
    var edge = step.getAttribute('data-edge');
    var panel = step.getAttribute('data-panel');
    var mark = step.getAttribute('data-mark');
    var hot = lineSet(step.getAttribute('data-lines'));

    tabs.forEach(function (tab) {
      var on = tab.getAttribute('data-tab') === track;
      tab.setAttribute('aria-selected', String(on));
      tab.tabIndex = on ? 0 : -1;
    });

    order.forEach(function (name) {
      lists[name].hidden = name !== track;
      codes[name].hidden = name !== track;
    });

    steps[track].forEach(function (li, i) {
      li.classList.toggle('is-current', i === index);
    });

    nodes.forEach(function (node) {
      node.classList.toggle('is-on', lit.indexOf(' ' + node.getAttribute('data-node') + ' ') !== -1);
    });
    edges.forEach(function (e) {
      e.classList.toggle('is-live', e.getAttribute('data-edge') === edge);
    });

    panels.forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== panel; });
    slotRows.forEach(function (row) {
      row.classList.toggle('is-hot', mark !== null && row.getAttribute('data-slot') === mark);
    });

    codes[track].querySelectorAll('.fdb-line').forEach(function (line) {
      line.classList.toggle('is-hot', !!hot[line.getAttribute('data-line')]);
    });

    dots.forEach(function (dot, i) {
      dot.classList.toggle('is-current', i === index);
      dot.setAttribute('aria-current', i === index ? 'step' : 'false');
    });
    counter.textContent = 'Step ' + (index + 1) + ' of ' + steps[track].length;
  }

  /* --- moving between steps ----------------------------------------- */

  function go(i) {
    index = Math.max(0, Math.min(steps[track].length - 1, i));
    render();
    if (playing) schedule();
  }

  function select(name, at) {
    if (order.indexOf(name) === -1) return;
    track = name;
    index = at || 0;
    buildDots();
    render();
    if (playing) schedule();
  }

  /** Next step; at the end of a track, the start of the next one. */
  function advance() {
    if (index < steps[track].length - 1) { go(index + 1); return true; }
    var at = order.indexOf(track);
    if (at < order.length - 1) { select(order[at + 1]); return true; }
    return false;
  }

  function retreat() {
    if (index > 0) { go(index - 1); return; }
    var at = order.indexOf(track);
    if (at > 0) select(order[at - 1], steps[order[at - 1]].length - 1);
  }

  function schedule() {
    window.clearTimeout(timer);
    timer = window.setTimeout(function () {
      if (!advance()) stop();
    }, HOLD);
  }

  function play() {
    playing = true;
    fdb.classList.add('is-playing');
    playButton.setAttribute('aria-label', 'Pause');
    schedule();
  }

  function stop(byUser) {
    if (byUser) userPaused = true;
    playing = false;
    fdb.classList.remove('is-playing');
    playButton.setAttribute('aria-label', 'Play');
    window.clearTimeout(timer);
  }

  /* --- wiring ------------------------------------------------------- */

  tabs.forEach(function (tab, i) {
    tab.addEventListener('click', function () { stop(true); select(tab.getAttribute('data-tab')); });
    tab.addEventListener('keydown', function (event) {
      var step = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
      if (!step) return;
      event.preventDefault();
      var target = tabs[(i + step + tabs.length) % tabs.length];
      stop(true);
      select(target.getAttribute('data-tab'));
      target.focus();
    });
  });

  controls.querySelector('[data-prev]').addEventListener('click', function () { stop(true); retreat(); });
  controls.querySelector('[data-next]').addEventListener('click', function () {
    stop(true);
    if (!advance()) select(order[0]);
  });
  playButton.addEventListener('click', function () {
    if (playing) { stop(true); return; }
    // Restarting from the very end starts the story over.
    if (index === steps[track].length - 1 && track === order[order.length - 1]) select(order[0]);
    userPaused = false;
    play();
  });

  /* Play while the widget is on screen and pause when it is not — but never
   * start on its own for a reader who asked for reduced motion, and never
   * restart something the reader deliberately paused. */
  if ('IntersectionObserver' in window) {
    var watcher = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        var visible = entry.isIntersecting && entry.intersectionRatio > 0.4;
        if (visible && !playing && !userPaused && !reduced) play();
        else if (!visible && playing) stop();
      });
    }, { threshold: [0, 0.4] });
    watcher.observe(fdb.querySelector('.fdb__grid'));
  }

  fdb.classList.add('is-live');
  controls.hidden = false;
  buildDots();
  render();
})();
