/**
 * Collection Tree Select — renders Omeka's flat collection <select> elements as a
 * searchable tree picker, and tints CollectionTree's tree page to match.
 *
 * The underlying <select> is left exactly as Omeka built it — same name, same
 * value, same submission — and select2 draws the hierarchy on top.
 *
 * window.CollectionTreeSelect supplies three collection_id-keyed maps from the
 * server: depths, parents (used to re-sort selects that arrive flat) and colours
 * (one hue per tree). Depth falls back to counting the label's indent prefix.
 *
 * Omeka calls jQuery.noConflict(), so $ is not global here. select2 is only
 * required for the picker: the tree page is decorated without it.
 *
 * @license GNU GPLv3
 */
(function (jQuery) {
    'use strict';

    if (!jQuery || !jQuery.fn) {
        return;
    }

    var config = window.CollectionTreeSelect || {};
    var depths = config.depths || {};
    var parents = config.parents || {};
    var colours = config.colours || {};
    var INDENT = config.indent > 0 ? config.indent : 18;
    var MIN_SEARCH = typeof config.minSearch === 'number' ? config.minSearch : 8;
    // 0 means "leave the plain <select> labels alone"; the picker still indents.
    var PER_LEVEL = typeof config.nbspPerLevel === 'number' && config.nbspPerLevel >= 0
        ? config.nbspPerLevel
        : 4;
    var i18n = config.i18n || {};
    var SELECTORS = (config.selectors || ['#collection-id']).join(',');

    var NBSP = '\u00a0';
    // Mirrors CollectionTreeSelectPlugin::stripIndent() — change both together.
    var PREFIX_RE = /^[-\u2010-\u2015\u00a0\u2000-\u200b>\u00bb\u203a\s]+/;
    var NBSP_RUN_RE = /^\u00a0+/;
    var DASH_RUN_RE = /^-+/;

    // Light enough that dark option text stays readable at every hue; deeper
    // levels fade further.
    var TINT_SATURATION = 60;
    var TINT_LIGHTNESS_ROOT = 88;
    var TINT_LIGHTNESS_CHILD = 91;
    var TINT_LIGHTNESS_STEP = 1.5;
    var TINT_LIGHTNESS_MAX = 96;
    // Full-strength edge stripe and selection dot.
    var ACCENT_SATURATION = 55;
    var ACCENT_LIGHTNESS = 55;

    function has(obj, key) {
        return Object.prototype.hasOwnProperty.call(obj, key);
    }

    function isEmpty(obj) {
        for (var key in obj) {
            if (has(obj, key)) {
                return false;
            }
        }
        return true;
    }

    /** Depth for one <option>: server map first, label prefix as a fallback. */
    function depthFor(option) {
        var value = String(option.id);
        if (value !== '' && has(depths, value)) {
            return depths[value];
        }

        var text = option.text || '';
        var match = text.match(NBSP_RUN_RE);
        if (match) {
            return Math.floor(match[0].length / (PER_LEVEL || 4));
        }
        match = text.match(DASH_RUN_RE);
        if (match) {
            return match[0].length;
        }
        return 0;
    }

    /** Bare collection name, with any indentation prefix removed. */
    function labelFor(option) {
        return (option.text || '').replace(PREFIX_RE, '');
    }

    /** Hue shared by this option's whole tree, or null when uncoloured. */
    function hueFor(option) {
        var value = String(option.id);
        return value !== '' && has(colours, value) ? colours[value] : null;
    }

    function tint(hue, depth) {
        var lightness = depth === 0
            ? TINT_LIGHTNESS_ROOT
            : Math.min(
                TINT_LIGHTNESS_MAX,
                TINT_LIGHTNESS_CHILD + (depth - 1) * TINT_LIGHTNESS_STEP
            );
        return 'hsl(' + hue + ',' + TINT_SATURATION + '%,' + lightness + '%)';
    }

    function accent(hue) {
        return 'hsl(' + hue + ',' + ACCENT_SATURATION + '%,' + ACCENT_LIGHTNESS + '%)';
    }

    /**
     * Entries that are not a collection, and so get no tree styling: Omeka's
     * empty "Select Below" placeholder, and "No Collection" under value 0.
     */
    function isPlaceholder(option) {
        var value = option.id;
        return value === '' || value === null || typeof value === 'undefined'
            || String(value) === '0';
    }

    function renderOption(option) {
        if (isPlaceholder(option)) {
            return jQuery('<span>').text(option.text);
        }

        var depth = depthFor(option);
        var hue = hueFor(option);
        // Custom properties; the stylesheet decides how they are painted.
        var style = '--cts-indent:' + (depth * INDENT) + 'px;';
        if (hue !== null) {
            style += '--cts-tint:' + tint(hue, depth) + ';--cts-accent:' + accent(hue) + ';';
        }

        return jQuery('<span class="cts-option">')
            .toggleClass('cts-root', depth === 0)
            .attr('data-cts-depth', depth)
            .attr('style', style)
            .text(labelFor(option));
    }

    function renderSelection(option) {
        if (isPlaceholder(option)) {
            return option.text;
        }

        var hue = hueFor(option);
        var $selection = jQuery('<span class="cts-selection">').text(labelFor(option));
        if (hue !== null) {
            // A dot, so the closed control still shows which tree this came from.
            $selection.addClass('cts-has-colour')
                .attr('style', '--cts-accent:' + accent(hue) + ';');
        }
        return $selection;
    }

    /** Match on the bare name so indentation never blocks a search hit. */
    function matcher(params, data) {
        var term = jQuery.trim(params.term || '');
        if (term === '') {
            return data;
        }
        if (typeof data.text === 'undefined') {
            return null;
        }
        return labelFor(data).toLowerCase().indexOf(term.toLowerCase()) > -1 ? data : null;
    }

    /**
     * Indent the plain-select labels with non-breaking spaces. The only place
     * batch edit's hierarchy can be expressed, since no PHP filter reaches it.
     */
    function reindent($select) {
        if (PER_LEVEL < 1) {
            return;
        }
        $select.find('option').each(function () {
            var $option = jQuery(this);
            var value = $option.attr('value');
            if (typeof value === 'undefined' || value === '' || !has(depths, value)) {
                return;
            }
            var name = $option.text().replace(PREFIX_RE, '');
            var pad = new Array(depths[value] * PER_LEVEL + 1).join(NBSP);
            $option.text(pad + name);
        });
    }

    /**
     * Re-sort a select's options into depth-first tree order.
     *
     * The batch-edit select bypasses collections_select_options, so it arrives
     * flat with children detached from their parents. Siblings keep their DOM
     * order, which makes this idempotent on an already-sorted select and so safe
     * to run everywhere. Unreachable options are appended, never dropped.
     */
    function reorder($select) {
        var select = $select[0];
        if (!select || isEmpty(parents)) {
            return;
        }
        // Flattening would lose the groups; nothing we target uses them.
        if (select.getElementsByTagName('optgroup').length) {
            return;
        }

        var options = Array.prototype.slice.call(select.options);
        var children = {};
        var roots = [];
        var leading = [];
        var trailing = [];
        var tracked = 0;

        options.forEach(function (option) {
            var value = option.getAttribute('value');
            if (value === null || value === '' || !has(parents, value)) {
                (tracked === 0 ? leading : trailing).push(option);
                return;
            }
            tracked++;
            var parent = String(parents[value]);
            if (parent === '0' || !has(parents, parent)) {
                roots.push(option);
                return;
            }
            if (!has(children, parent)) {
                children[parent] = [];
            }
            children[parent].push(option);
        });

        if (tracked === 0) {
            return;
        }

        var ordered = [];
        var visited = {};

        // Explicit stack rather than recursion: depth is user data.
        var stack = roots.slice().reverse();
        while (stack.length) {
            var option = stack.pop();
            var value = option.getAttribute('value');
            if (has(visited, value)) {
                continue;
            }
            visited[value] = true;
            ordered.push(option);

            var kids = children[value];
            if (kids) {
                for (var i = kids.length - 1; i >= 0; i--) {
                    stack.push(kids[i]);
                }
            }
        }

        options.forEach(function (candidate) {
            var value = candidate.getAttribute('value');
            if (value !== null && value !== '' && has(parents, value) && !has(visited, value)) {
                ordered.push(candidate);
            }
        });

        var previous = select.value;
        var fragment = document.createDocumentFragment();
        leading.concat(ordered, trailing).forEach(function (option) {
            fragment.appendChild(option);
        });
        select.appendChild(fragment);

        // Moving option nodes can reset selectedIndex in some browsers.
        if (select.value !== previous) {
            select.value = previous;
        }
    }

    function enhance(select) {
        var $select = jQuery(select);
        if ($select.hasClass('select2-hidden-accessible')) {
            return;
        }

        reorder($select);
        reindent($select);

        $select.select2({
            width: '100%',
            minimumResultsForSearch: MIN_SEARCH,
            templateResult: renderOption,
            templateSelection: renderSelection,
            matcher: matcher,
            language: {
                noResults: function () {
                    return i18n.noMatch || 'No matching collections';
                }
            }
        });
    }

    /**
     * Re-init widgets initialised while hidden: Omeka's admin tabs toggle
     * visibility, and select2 measures 0 width inside a display:none container.
     */
    function refreshHidden() {
        jQuery(SELECTORS).each(function () {
            var $select = jQuery(this);
            var $container = $select.next('.select2-container');
            if (!$select.is(':visible') || !$container.length) {
                return;
            }
            if ($container.outerWidth() < 40) {
                $select.select2('destroy');
                enhance(this);
            }
        });
    }

    // CollectionTree's helpers emit plain link_to_collection() markup with no id
    // or class on the row, so the href is the only place the id appears.
    var SHOW_RE = /\/collections\/show\/(\d+)/;

    function collectionIdFromLink(link) {
        var href = link.getAttribute('href') || '';
        var match = href.match(SHOW_RE);
        return match ? match[1] : null;
    }

    /** The collection whose show page this is, if that is where we are. */
    function currentCollectionId() {
        var match = String(window.location.pathname).match(SHOW_RE);
        return match ? match[1] : null;
    }

    /**
     * Tint CollectionTree's nested #collection-tree list to match the picker.
     *
     * Depth comes from the DOM nesting rather than the server map, so the
     * indentation matches what is actually on the page when a private ancestor is
     * missing. The colour still comes from the map, so a subcollection keeps its
     * root's hue even when that root is not shown.
     */
    function decorateTreePage() {
        var root = document.getElementById('collection-tree');
        if (!root) {
            return;
        }

        var $root = jQuery(root).addClass('cts-tree');
        var rows = [];
        var unlinked = [];

        $root.find('li').each(function () {
            var $item = jQuery(this);
            // parentsUntil stops below #collection-tree, so the outermost <ul> is
            // always counted and a root row would otherwise score 1.
            var depth = Math.max(0, $item.parentsUntil(root, 'ul').length - 1);

            // This row's own label; the children's <ul> is a sibling of it.
            var $label = $item.children('a').first();
            var id = $label.length ? collectionIdFromLink($label[0]) : null;

            if (!$label.length) {
                // Two rows arrive without a link: the collection whose page this
                // is, and "[Unavailable]" for a private ancestor. Wrap the bare
                // text so it can take the same row treatment.
                $label = $item.contents().filter(function () {
                    return this.nodeType === 3 && jQuery.trim(this.nodeValue) !== '';
                }).first().wrap('<span>').parent();
                if ($label.length) {
                    unlinked.push($label);
                }
            }

            if ($label.length) {
                rows.push({ $label: $label, depth: depth, id: id });
            }
        });

        // The current collection can be identified from the URL — but only when
        // it is the sole unlinked row, since the two are indistinguishable in the
        // markup and guessing would colour "[Unavailable]" wrongly.
        if (unlinked.length === 1) {
            var currentId = currentCollectionId();
            if (currentId !== null) {
                for (var i = 0; i < rows.length; i++) {
                    if (rows[i].$label[0] === unlinked[0][0]) {
                        rows[i].id = currentId;
                        break;
                    }
                }
            }
        }

        rows.forEach(function (row) {
            var style = '--cts-indent:' + (row.depth * INDENT) + 'px;';
            var tinted = row.id !== null && has(colours, row.id);
            if (tinted) {
                var hue = colours[row.id];
                style += '--cts-tint:' + tint(hue, row.depth)
                       + ';--cts-accent:' + accent(hue) + ';';
            }

            row.$label.addClass('cts-row')
                .toggleClass('cts-root', row.depth === 0)
                // Only a tinted row overrides the theme's background; see the CSS.
                .toggleClass('cts-tinted', tinted)
                .attr('data-cts-depth', row.depth)
                .attr('style', style);
        });
    }

    jQuery(document).ready(function () {
        if (config.treePage) {
            decorateTreePage();
        }

        // The tree page above needs no select2, and is never queued with it.
        if (!jQuery.fn.select2) {
            return;
        }

        jQuery(SELECTORS).each(function () {
            enhance(this);
        });

        jQuery(document).on('click', '.section-nav a, #section-nav a, .tabs a, ul.tabs li a', function () {
            window.setTimeout(refreshHidden, 0);
        });
        jQuery(window).on('resize', function () {
            window.setTimeout(refreshHidden, 0);
        });
    });
}(window.jQuery));
