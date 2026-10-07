# Collection Tree Select

An Omeka Classic plugin, and a companion to
[Collection Tree](https://github.com/omeka/plugin-CollectionTree). It turns the
flat **Collection** dropdowns into a searchable tree picker, colour-codes every
collection by the top-level collection it belongs to, and applies the same
colours to Collection Tree's own tree page.

Collection Tree is left completely untouched, so it survives its own upgrades.

## Why

A native `<select>` cannot nest. `<optgroup>` gives exactly one level and its
labels are not selectable, which is wrong for Omeka, where a parent collection is
a real collection you may want to assign items to. The only way to get arbitrary
depth with every level still choosable is to keep the native `<select>` as the
form value and render the tree over it.

## Requirements

- Omeka Classic 2.0+ (developed and tested against 3.2.1)
- The Collection Tree plugin, 2.1 or 2.2, installed and active

## Install

1. Download the ZIP from [Releases](https://github.com/raphihalff/omeka-plugin-CollectionTreeSelect/releases)
   and unpack it into your Omeka `plugins/` directory, so you have
   `plugins/CollectionTreeSelect/`.
2. Install and activate it under **Settings → Plugins**. Collection Tree must be
   active first; it is declared as a required plugin.

## What it changes

| Where | What happens |
|---|---|
| Item **Add** / **Edit** → Collection | Tree picker |
| Item **Batch Edit** → Collection | Tree picker, and the options are re-sorted into tree order |
| Collection **Edit** → Parent Collection | Tree picker over Collection Tree's own select |
| **Advanced search** → Search By Collection | Tree picker, plus the subcollections Collection Tree strips out on the public side |
| **Collection Tree** page, and the tree on a collection's page | Rows tinted to match the dropdowns |

Without JavaScript the dropdowns stay native and fall back to non-breaking-space
indentation.

## Configuration

**Settings → Plugins → Collection Tree Select → Configure**

| Setting | Default | Effect |
|---|---|---|
| Enable tree picker | on | Off leaves dropdowns native, indentation only |
| Colour-code trees | on | Tint each collection with its top-level ancestor's colour |
| Starting colour | 210 | Hue of the first tree, in degrees: 0 red, 120 green, 210 blue, 300 magenta |
| Colour intensity | 50 | Tint strength, 0 barely-there to 100 vivid |
| Colour the Collection Tree page | on | Extend those colours to the tree page |
| Full collection list in dropdowns | on | Restore subcollections stripped from public select options |
| Apply to the public site | **off** | Extend all of the above to the public theme |
| Indent per level (px) | 18 | Indentation in the picker |
| Show search box from | 8 | Collections needed before the search field appears; 0 always shows it |
| Plain-select indent | 4 | Non-breaking spaces per level in the no-JavaScript fallback |

**Apply to the public site** is off by default because the tree page is re-laid
out as full-width rows, which is a visible change to your theme. Turn it on and
check the result.

## How it works

The `collection_trees` table is read once per request and emitted as three
`collection_id`-keyed maps — depth, parent and hue. The browser never has to
parse label prefixes, though it will as a fallback. Collections orphaned or left
in a cycle by a bad import are treated as roots rather than hanging the request.

**Indentation is replaced, not added to.** Every label is stripped back to the
bare collection name and re-padded, in PHP (`stripIndent()`) and again in
JavaScript (`PREFIX_RE`). Feed it `  -- Faculty letters` and you get one
clean `Faculty letters` at depth 2. There is no need to edit Collection Tree, and
you should not.

**Filter order matters.** Collection Tree registers
`collections_select_options` at the default priority and its implementation ends
with `array_intersect_key($treeOptions, $options)`, discarding whatever ran
before it. This plugin registers at priority 100 so it always runs last,
regardless of which plugin was installed first.

**Batch edit is re-sorted in the browser.** `items/batch-edit-common.php` calls
`findPairsForSelectForm()` directly, so no PHP filter can reach it and its
subcollections arrive detached from their parents. The script re-sorts them
depth-first. Siblings keep their existing order, which makes the pass idempotent
on selects that are already correct, so it is applied everywhere.

**Colours are stable.** Each top-level collection gets a hue, assigned by
position among the roots sorted by id, using the base-2 van der Corput sequence.
Adding a child or renaming a collection never reshuffles the palette; only adding
or removing a top-level collection shifts the roots after it. Spacing is exactly
optimal at 2, 4, 8 and 16 roots and no worse than about 62% of optimal in between.

**Starting colour** rotates that whole wheel, so you can bring the palette toward
your site's own colours without losing the spacing. **Colour intensity** scales
saturation and darkness together; the coloured edge stripe keeps its strength at
any setting, so trees stay distinguishable even at 0. Both are clamped on save,
and 50 reproduces the palette exactly as it shipped before the settings existed.

**The tree page is restyled assertively.** It belongs to your theme, so the
plugin's selectors match on `#collection-tree` as well as its own class, and the
tint is the one declaration marked `!important`. That is scoped to rows which
actually have a colour, so uncoloured rows keep your theme's background. Text on a
tinted row is forced to a dark neutral, since the tints are 88–96% light and a
theme's pale link text would be unreadable on them.

## Advanced search listed only top-level collections

On the public side only, and this is the reason the plugin restores them.

Collection Tree's "browse only root collections" setting hides child collections
from the collections browse, via `hookCollectionsBrowseSql()`. That is aimed at
the browse page, but select options are built by `findPairsForSelectForm()`,
which routes through `getSelectForFindBy()` and fires the very same
`collections_browse_sql` hook. The public advanced search therefore offered root
collections only, with no way to search inside a subcollection. The admin side
was never affected, because the hook returns early on `is_admin_theme()`.

Collection Tree 2.2 added an opt-out: the hook returns early when the browse
params carry `collection_tree_all_collections`. **2.1 has the rule but no
opt-out**, so this plugin tries the documented route, checks whether the answer
actually came back complete, and if not falls back to building the list from
`Table_Collection::getSelect()` — which applies Omeka's public-permission
filtering but, unlike `getSelectForFindBy()`, never fires the browse hook.

Either way, a visitor sees exactly the collections they were allowed to see
before, plus the subcollections among them. Nothing private is exposed.

The restoration is conservative: if any nested collection is still present then
nothing was stripped, and the options are left alone — so another plugin
narrowing the dropdown for its own reasons is never overridden.

It also puts back the **No Collection** entry, which the advanced search form
requests via `include_no_collection` and Collection Tree drops.

## Extension points

| Filter | Receives | Use |
|---|---|---|
| `collection_tree_select_selectors` | array of CSS selectors | Enhance a collection select the plugin does not know about |
| `collection_tree_select_pages` | `module => controller => [actions]` | Register a page carrying such a select |
| `collection_tree_select_tree_pages` | `module => controller => [actions]` | Register a page rendering `#collection-tree` |

The page maps gate asset loading, so a select on an unregistered page is never
enhanced however well its selector matches.

## Notes

- select2 4.0.13 is vendored under `views/shared/{javascripts,css}/vendor/`. No
  CDN is contacted. It is MIT licensed — see `views/shared/LICENSE.select2.md`.
- Assets load only on pages that carry a collection dropdown or the tree markup.
  select2 is ~95KB and is skipped on the tree page, which does not need it.
- Omeka calls `jQuery.noConflict()`, so the script uses `jQuery`, not `$`.
- Upgrading seeds any newly added settings via `hookUpgrade`, since
  `_installOptions()` only runs on a fresh install.

## Development

See [test/README.md](test/README.md). Two suites, neither needing an Omeka
install: `php test/test-tree.php` for the server-side logic, and a browser page
for the picker, the colours and theme conflicts.

## License

GPLv3, matching Collection Tree. See [LICENSE](LICENSE).
