# Tests

Two self-contained suites. Neither needs an Omeka install, a database, or any
build step — Omeka is stubbed. They are excluded from the release archive by
`.gitattributes`, so they never reach a user's `plugins/` directory.

## PHP — tree building, hues, ordering, option restoration

```bash
php test/test-tree.php
```

53 checks over `CollectionTreeSelectPlugin`: depth/parent/root maps (including
dangling parents, cycles and pathological nesting), hue assignment and its
stability, the hue and intensity settings and their clamping, depth-first
ordering, and the restoration of subcollections that
Collection Tree strips from public select options — covering both Collection Tree
2.2, which honours the documented opt-out, and 2.1, which does not.

Exits non-zero on failure, so it works as a CI step.

## Browser — the picker, the tree page, and theme conflicts

Needs jQuery, which is not vendored:

```bash
mkdir -p test/vendor && curl -L -o test/vendor/jquery.min.js https://code.jquery.com/jquery-3.7.1.min.js
```

Then serve the plugin directory and open the page — `file://` will not work,
because the browser blocks reading the stylesheet rules it asserts on:

```bash
python3 -m http.server 8000
```

- <http://localhost:8000/test/test.html> — 23 checks: option ordering, select2
  rendering, the colours, and the tree page under a deliberately hostile theme
  that sets id-scoped backgrounds and its own list indentation. Add
  `&intensity=0` or `&intensity=100` to check the ends of the intensity scale;
  the default run asserts that intensity 50 reproduces the original palette
  exactly.
- <http://localhost:8000/test/test.html?case=show> — 3 checks for a collection
  show page, where the current collection's row has no link.

Results render on the page; `window.__ctsResults` holds `{failures, count}` if you
want to drive it from an automated browser.
