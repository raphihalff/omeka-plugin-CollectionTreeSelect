<?php
/**
 * Collection Tree Select
 *
 * Companion to the CollectionTree plugin: turns the flat Collection dropdowns
 * into a searchable, indented, colour-coded tree picker, and tints
 * CollectionTree's own tree page to match. CollectionTree is left untouched.
 *
 * @copyright Copyright 2026 Raphael Halff
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU GPLv3
 */

class CollectionTreeSelectPlugin extends Omeka_Plugin_AbstractPlugin
{
    /** Asset cache-buster. Bump when shipping changed JS/CSS. */
    const ASSET_VERSION = '1.3.1';

    /** Non-breaking space (U+00A0), UTF-8. */
    const NBSP = "\xC2\xA0";

    /** Runs after AbstractPlugin's default of 10. @see setUp() */
    const FILTER_PRIORITY = 100;

    /** Runs before everyone else's. @see captureCollectionsSelectOptions() */
    const FILTER_PRIORITY_CAPTURE = 1;

    /** Hue of the first root, in degrees. */
    const HUE_ORIGIN = 210;

    /** How far up a parent chain _getTree() walks before giving up. */
    const MAX_DEPTH = 64;

    protected $_hooks = array(
        'install',
        'uninstall',
        'upgrade',
        'initialize',
        'admin_head',
        'public_head',
        'config_form',
        'config',
    );

    /** collections_select_options is registered in setUp() instead, for priority. */
    protected $_filters = array();

    protected $_options = array(
        // Master switch for the select2 widget. Off = indentation only.
        'collection_tree_select_enable_js' => 1,
        'collection_tree_select_indent' => 18,
        // Hide the search box until there are at least this many collections.
        'collection_tree_select_min_search' => 8,
        // Non-breaking spaces per level in the plain (no-JS) select. 0 = leave alone.
        'collection_tree_select_nbsp_per_level' => 4,
        'collection_tree_select_colour_roots' => 1,
        // Extend all of the above to the public theme.
        'collection_tree_select_public' => 0,
        // Put back collections CollectionTree strips from select options.
        'collection_tree_select_full_options' => 1,
        'collection_tree_select_colour_tree_page' => 1,
    );

    /** Checkboxes are absent from the POST when unticked. @see hookConfig() */
    protected $_checkboxOptions = array(
        'collection_tree_select_enable_js',
        'collection_tree_select_colour_roots',
        'collection_tree_select_public',
        'collection_tree_select_full_options',
        'collection_tree_select_colour_tree_page',
    );

    /** @var array|null Memoised depths/parents/roots. */
    protected $_tree = null;

    /** @var array|null Select options as they arrived, before any filter ran. */
    protected $_incomingOptions = null;

    /** @var array|null Memoised unrestricted collection list. */
    protected $_allPairs = null;

    /**
     * CollectionTree registers collections_select_options at the default priority
     * of 10 and its implementation ends with array_intersect_key($treeOptions,
     * $options), discarding whatever ran before it. Registering explicitly here
     * fixes the order instead of leaving it to plugin load order.
     */
    public function setUp()
    {
        parent::setUp();

        add_filter(
            'collections_select_options',
            array($this, 'captureCollectionsSelectOptions'),
            self::FILTER_PRIORITY_CAPTURE
        );

        add_filter(
            'collections_select_options',
            array($this, 'filterCollectionsSelectOptions'),
            self::FILTER_PRIORITY
        );
    }

    public function hookInitialize()
    {
        $languages = dirname(__FILE__) . '/languages';
        if (is_dir($languages)) {
            add_translation_source($languages);
        }
    }

    public function hookInstall()
    {
        $this->_installOptions();
    }

    public function hookUninstall()
    {
        $this->_uninstallOptions();
    }

    /** Seed options added after the plugin was first installed. */
    public function hookUpgrade($args)
    {
        foreach ($this->_options as $key => $default) {
            if (get_option($key) === null) {
                set_option($key, $default);
            }
        }
    }

    public function hookConfigForm()
    {
        echo get_view()->partial('plugins/collection-tree-select-config-form.php');
    }

    public function hookConfig($args)
    {
        $post = $args['post'];
        foreach ($this->_options as $key => $default) {
            if (isset($post[$key])) {
                set_option($key, $post[$key]);
            }
        }
        foreach ($this->_checkboxOptions as $key) {
            if (!isset($post[$key])) {
                set_option($key, 0);
            }
        }
    }

    /**
     * Record the options before any filter has touched them.
     *
     * Only this early pass sees the non-collection entries the caller asked for,
     * such as the advanced search form's "No Collection" under key 0.
     *
     * @param array $options
     * @return array unchanged
     */
    public function captureCollectionsSelectOptions($options)
    {
        $this->_incomingOptions = $options;
        return $options;
    }

    /**
     * Restore what CollectionTree dropped, sort into tree order, re-indent.
     *
     * Reaches only selects built via get_table_options(). Batch edit and
     * CollectionTree's Parent Collection tab call findPairsForSelectForm()
     * directly and bypass every filter; the JS handles those.
     *
     * @param array $options collection_id => label
     * @return array
     */
    public function filterCollectionsSelectOptions($options)
    {
        $depths = $this->_getDepths();
        if (empty($depths)) {
            return $options;
        }

        $options = $this->_restoreStrippedDescendants($options);
        $options = $this->_sortByTree($options);

        $perLevel = (int) get_option('collection_tree_select_nbsp_per_level');
        if ($perLevel < 1) {
            return $options;
        }

        // Replaces CollectionTree's padding rather than stacking on it.
        foreach ($options as $id => $label) {
            if (!isset($depths[$id])) {
                continue;
            }
            $options[$id] = str_repeat(self::NBSP, $perLevel * $depths[$id])
                          . $this->stripIndent($label);
        }

        return $options;
    }

    /**
     * Put back the child collections CollectionTree removed from a dropdown.
     *
     * Its hookCollectionsBrowseSql() hides non-root collections on the public
     * side when collection_tree_browse_only_root is set. That targets the browse
     * page, but findPairsForSelectForm() fires the same hook, so the public
     * advanced search ends up offering roots only.
     *
     * Conservative by design: acts only when the list contains no nested
     * collection at all, so another plugin narrowing the options is left alone.
     *
     * @param array $options
     * @return array
     */
    protected function _restoreStrippedDescendants($options)
    {
        if (!get_option('collection_tree_select_full_options')) {
            return $options;
        }
        if (!$this->_hasNesting() || $this->_containsDescendant($options)) {
            return $options;
        }

        $tree = $this->_getTree();
        $all = $this->_getAllPairs();
        if (empty($all)) {
            return $options;
        }

        // Keep non-collection entries ("No Collection") in front, where Omeka put
        // them; CollectionTree's array_intersect_key() drops them.
        $extras = array();
        $source = $this->_incomingOptions === null ? $options : $this->_incomingOptions;
        foreach ($source as $id => $label) {
            if (!isset($tree['depths'][$id])) {
                $extras[$id] = $label;
            }
        }

        // Union, not array_merge: these keys are collection ids and must survive.
        return $extras + $all;
    }

    /** @return bool Whether any collection is nested under another. */
    protected function _hasNesting()
    {
        $tree = $this->_getTree();
        foreach ($tree['depths'] as $depth) {
            if ($depth > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $pairs
     * @return bool Whether the list holds at least one non-root collection.
     */
    protected function _containsDescendant($pairs)
    {
        $tree = $this->_getTree();
        foreach ($pairs as $id => $label) {
            if (!empty($tree['depths'][$id])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every collection the current user may see, ignoring the root-only rule.
     *
     * CollectionTree 2.2 honours a collection_tree_all_collections browse param;
     * 2.1, the current release, has the rule but no opt-out. So the supported
     * route is tried first and checked — if it comes back without a single child
     * collection it was not honoured, and the fallback query takes over.
     *
     * @return array collection_id => title
     */
    protected function _getAllPairs()
    {
        if ($this->_allPairs !== null) {
            return $this->_allPairs;
        }

        $this->_allPairs = array();

        try {
            $pairs = $this->_db->getTable('Collection')->findPairsForSelectForm(
                array('collection_tree_all_collections' => true)
            );
        } catch (Exception $e) {
            // A themed public page should not 500 over a cosmetic dropdown.
            $pairs = array();
        }

        if ($this->_containsDescendant($pairs)) {
            $this->_allPairs = $pairs;
            return $this->_allPairs;
        }

        try {
            $this->_allPairs = $this->_findPairsBypassingBrowseHook();
        } catch (Exception $e) {
            $this->_allPairs = $pairs;
        }

        return $this->_allPairs;
    }

    /**
     * findPairsForSelectForm() rebuilt on a select that skips the browse hook.
     *
     * collections_browse_sql is fired by getSelectForFindBy(), not by
     * getSelect(), so starting from the latter keeps Omeka's public-permission
     * filtering while leaving CollectionTree's root-only rule out. The title join
     * is transcribed from Omeka's own query and will drift if that changes.
     *
     * @return array collection_id => title
     */
    protected function _findPairsBypassingBrowseHook()
    {
        $db = $this->_db;

        $subquery = new Omeka_Db_Select;
        $subquery->from(array('element_texts' => $db->ElementText), 'id');
        $subquery->joinInner(
            array('elements' => $db->Element),
            'elements.id = element_texts.element_id',
            array()
        );
        $subquery->joinInner(
            array('element_sets' => $db->ElementSet),
            'element_sets.id = elements.element_set_id',
            array()
        );
        $subquery->where("element_sets.name = 'Dublin Core'");
        $subquery->where("elements.name = 'Title'");
        $subquery->where("element_texts.record_type = 'Collection'");
        $subquery->where('element_texts.record_id = collections.id');
        $subquery->limit(1);

        $select = $db->getTable('Collection')->getSelect();
        $select->joinLeft(
            array('element_texts' => $db->ElementText),
            "element_texts.id = ($subquery)",
            array()
        );
        $select->reset(Zend_Db_Select::COLUMNS);
        $select->from(array(), array('collections.id', 'element_texts.text'));
        $select->order('element_texts.text');

        $pairs = $db->fetchPairs($select);
        foreach ($pairs as $id => &$name) {
            $name = ($name === null || $name === '')
                ? __('[Untitled] #%s', $id)
                : strip_formatting($name);
        }
        unset($name);

        return $pairs;
    }

    /**
     * Reorder options depth-first so children follow their parent.
     *
     * Siblings keep their existing order, which makes this idempotent on a list
     * already in tree order. Mirrors reorder() in collection-tree-select.js.
     *
     * @param array $options
     * @return array
     */
    protected function _sortByTree($options)
    {
        $tree = $this->_getTree();
        $parents = $tree['parents'];

        $children = array();
        $roots = array();
        $leading = array();
        $trailing = array();
        $tracked = 0;

        foreach ($options as $id => $label) {
            // Entries with no tree row lead the list if they came first
            // ("No Collection"), otherwise they follow it.
            if (!isset($parents[$id])) {
                if ($tracked === 0) {
                    $leading[$id] = $label;
                } else {
                    $trailing[$id] = $label;
                }
                continue;
            }
            $tracked++;
            $parent = $parents[$id];
            if (!$parent || !isset($parents[$parent])) {
                $roots[] = $id;
            } else {
                $children[$parent][] = $id;
            }
        }

        if ($tracked === 0) {
            return $options;
        }

        $ordered = array();
        $visited = array();
        // Explicit stack rather than recursion: depth is user data.
        $stack = array_reverse($roots);
        while ($stack) {
            $id = array_pop($stack);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $ordered[$id] = $options[$id];

            if (isset($children[$id])) {
                foreach (array_reverse($children[$id]) as $childId) {
                    $stack[] = $childId;
                }
            }
        }

        // Append anything the walk could not reach rather than losing it.
        foreach ($options as $id => $label) {
            if (isset($parents[$id]) && !isset($visited[$id])) {
                $ordered[$id] = $label;
            }
        }

        return $leading + $ordered + $trailing;
    }

    /**
     * Strip leading tree indentation from a collection label.
     *
     * Covers CollectionTree's hyphens, this plugin's non-breaking spaces, and the
     * dashes and arrows people substitute for them. Mirrors PREFIX_RE in
     * collection-tree-select.js — change both together.
     *
     * @param string $label
     * @return string
     */
    public function stripIndent($label)
    {
        $stripped = preg_replace(
            '/^[-\x{2010}-\x{2015}\x{00A0}\x{2000}-\x{200B}>\x{00BB}\x{203A}\s]+/u',
            '',
            (string) $label
        );

        // preg_replace returns null on invalid UTF-8; keep the original then.
        return $stripped === null ? $label : $stripped;
    }

    public function hookAdminHead($args)
    {
        if (!is_admin_theme()) {
            return;
        }
        $this->_queueAssets();
    }

    /** Public theme is opt-in: it has styling of its own. */
    public function hookPublicHead($args)
    {
        if (is_admin_theme() || !get_option('collection_tree_select_public')) {
            return;
        }
        $this->_queueAssets();
    }

    /** Queue only what this page needs; the tree page needs no select2. */
    protected function _queueAssets()
    {
        $colours = $this->_getHues();

        $wantsPicker = get_option('collection_tree_select_enable_js')
                     && $this->_isSelectPage();
        $wantsTreePage = !empty($colours)
                       && get_option('collection_tree_select_colour_tree_page')
                       && $this->_isTreePage();

        if (!$wantsPicker && !$wantsTreePage) {
            return;
        }

        $tree = $this->_getTree();

        $config = array(
            'depths'       => (object) $tree['depths'],
            // Drives the client-side re-sort of selects that arrive flat.
            'parents'      => (object) $tree['parents'],
            // collection_id => hue, shared by a whole tree. Empty when colours off.
            'colours'      => (object) $colours,
            'indent'       => (int) get_option('collection_tree_select_indent'),
            'minSearch'    => (int) get_option('collection_tree_select_min_search'),
            'nbspPerLevel' => (int) get_option('collection_tree_select_nbsp_per_level'),
            'treePage'     => (bool) $wantsTreePage,
            'selectors'    => apply_filters('collection_tree_select_selectors', array(
                // Item add / edit.
                '#collection-id',
                'select[name="collection_id"]',
                // Item batch edit.
                'select[name="metadata[collection_id]"]',
                // CollectionTree's Parent Collection tab.
                '#collection_tree_parent_collection_id',
                'select[name="collection_tree_parent_collection_id"]',
                // Advanced item search, admin and public.
                '#collection-search',
                'select[name="collection"]',
            )),
            'i18n' => array(
                'search'  => __('Search collections'),
                'noMatch' => __('No matching collections'),
            ),
        );

        // Config first, then the library, then our init. jQuery is prepended by
        // head_js() afterwards, so it is present before any of these run.
        queue_js_string('window.CollectionTreeSelect = ' . json_encode($config) . ';');
        if ($wantsPicker) {
            queue_js_file('vendor/select2.full.min', 'javascripts', array(), self::ASSET_VERSION);
            queue_css_file('vendor/select2.min', 'all', false, 'css', self::ASSET_VERSION);
        }
        queue_js_file('collection-tree-select', 'javascripts', array(), self::ASSET_VERSION);
        queue_css_file('collection-tree-select', 'all', false, 'css', self::ASSET_VERSION);
    }

    /**
     * Pages carrying a collection dropdown. Also gates the ~95KB select2 payload.
     *
     * @return bool
     */
    protected function _isSelectPage()
    {
        $allowed = array(
            'default' => array(
                // browse and search both render items/search-form.php.
                'items'       => array('add', 'edit', 'batch-edit', 'batchEdit', 'browse', 'search'),
                'collections' => array('add', 'edit'),
            ),
        );

        $allowed = apply_filters('collection_tree_select_pages', $allowed);

        return $this->_matchesRoute($allowed);
    }

    /**
     * Pages rendering CollectionTree's #collection-tree markup: its own tree page,
     * and any collection show page.
     *
     * @return bool
     */
    protected function _isTreePage()
    {
        $allowed = array(
            'collection-tree' => array(
                'index' => array('index'),
            ),
            'default' => array(
                'collections' => array('show'),
            ),
        );

        $allowed = apply_filters('collection_tree_select_tree_pages', $allowed);

        return $this->_matchesRoute($allowed);
    }

    /**
     * @param array $allowed module => controller => [actions]
     * @return bool
     */
    protected function _matchesRoute($allowed)
    {
        $request = Zend_Controller_Front::getInstance()->getRequest();
        if (!$request) {
            return false;
        }

        $module     = $request->getModuleName();
        $controller = $request->getControllerName();
        $action     = $request->getActionName();

        if (!isset($allowed[$module][$controller])) {
            return false;
        }

        return in_array($action, $allowed[$module][$controller], true);
    }

    /** @return array collection_id => depth */
    protected function _getDepths()
    {
        $tree = $this->_getTree();
        return $tree['depths'];
    }

    /**
     * Build depth, parent and root maps from the collection_trees table.
     *
     * Roots are depth 0, parent 0, their own root. A row in a cycle, or nested
     * past MAX_DEPTH, is demoted to a root: that avoids hanging the request and
     * keeps it reachable by the depth-first walks, which descend from the roots.
     *
     * @return array array('depths' => array, 'parents' => array, 'roots' => array)
     */
    protected function _getTree()
    {
        if ($this->_tree !== null) {
            return $this->_tree;
        }

        $this->_tree = array('depths' => array(), 'parents' => array(), 'roots' => array());

        if (!plugin_is_active('CollectionTree')) {
            return $this->_tree;
        }

        try {
            $rows = $this->_db->fetchAll(
                "SELECT collection_id, parent_collection_id FROM {$this->_db->CollectionTree}"
            );
        } catch (Exception $e) {
            return $this->_tree;
        }

        $parents = array();
        foreach ($rows as $row) {
            $parents[(int) $row['collection_id']] = (int) $row['parent_collection_id'];
        }

        foreach ($parents as $id => $parent) {
            $depth   = 0;
            $root    = $id;
            $current = $parent;
            $seen    = array($id => true);
            $broken  = false;

            while ($current && isset($parents[$current])) {
                if (isset($seen[$current]) || $depth >= self::MAX_DEPTH) {
                    $broken = true;
                    break;
                }
                $seen[$current] = true;
                $depth++;
                $root    = $current;
                $current = $parents[$current];
            }

            // Ending on a parent with no row of its own is not an error: the chain
            // stops there and the last collection reached is the root.
            if ($broken) {
                $depth = 0;
                $root  = $id;
            }

            $this->_tree['depths'][$id]  = $depth;
            $this->_tree['roots'][$id]   = $root;
            $this->_tree['parents'][$id] = $depth === 0 ? 0 : $parent;
        }

        return $this->_tree;
    }

    /**
     * One hue per tree, shared by every collection in it.
     *
     * Roots are sorted by id and coloured by position via the base-2 van der
     * Corput sequence, which keeps every prefix near-evenly spread around the
     * circle. Hues therefore stay put as collections are added or renamed; only
     * adding or removing a root shifts the roots after it.
     *
     * @return array collection_id => hue in degrees
     */
    protected function _getHues()
    {
        if (!get_option('collection_tree_select_colour_roots')) {
            return array();
        }

        $tree = $this->_getTree();
        if (empty($tree['roots'])) {
            return array();
        }

        $roots = array_unique(array_values($tree['roots']));
        sort($roots);

        $hues = array();
        foreach ($roots as $index => $rootId) {
            $hue = self::HUE_ORIGIN + 360 * $this->_radicalInverse($index);
            $hues[$rootId] = round(fmod($hue, 360), 1);
        }

        $colours = array();
        foreach ($tree['roots'] as $id => $rootId) {
            $colours[$id] = $hues[$rootId];
        }

        return $colours;
    }

    /**
     * Base-2 radical inverse: the bits of $index read back to front as a binary
     * fraction. 0, 1, 2, 3 give 0, 0.5, 0.25, 0.75.
     *
     * @param int $index
     * @return float in [0, 1)
     */
    protected function _radicalInverse($index)
    {
        $result = 0.0;
        $fraction = 0.5;

        while ($index > 0) {
            $result  += $fraction * ($index % 2);
            $index    = (int) ($index / 2);
            $fraction /= 2;
        }

        return $result;
    }
}
