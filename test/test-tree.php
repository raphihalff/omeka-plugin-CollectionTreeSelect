<?php
/** Exercises CollectionTreeSelectPlugin against a stubbed Omeka. */

abstract class Omeka_Plugin_AbstractPlugin
{
    protected $_db;
    public function setUp() {}
    public function __construct($db = null) { $this->_db = $db; }
}

$GLOBALS['options'] = array();
function plugin_is_active($n) { return true; }
function get_option($n) { return isset($GLOBALS['options'][$n]) ? $GLOBALS['options'][$n] : null; }
function apply_filters($n, $v) { return $v; }
function __($s) { return $s; }
function strip_formatting($s) { return $s; }

/** $honoursOptOut models CollectionTree 2.2 (true) vs 2.1 (false). */
class FakeCollectionTable
{
    public $pairs, $rootsOnly, $lastParams = null, $calls = 0, $honoursOptOut = true;
    public function __construct($pairs, $rootsOnly = null)
    {
        $this->pairs = $pairs;
        $this->rootsOnly = $rootsOnly;
    }
    public function findPairsForSelectForm(array $params = array())
    {
        $this->lastParams = $params;
        $this->calls++;
        return (!$this->honoursOptOut && $this->rootsOnly !== null) ? $this->rootsOnly : $this->pairs;
    }
}

class FakeDb
{
    public $CollectionTree = 'omeka_collection_trees';
    public $collectionTable;
    private $_rows;
    public function __construct($rows, $pairs = array(), $rootsOnly = null)
    {
        $this->_rows = $rows;
        $this->collectionTable = new FakeCollectionTable($pairs, $rootsOnly);
    }
    public function fetchAll($sql) { return $this->_rows; }
    public function getTable($n) { return $this->collectionTable; }
}

require dirname(__DIR__) . '/CollectionTreeSelectPlugin.php';

/** The raw-SQL fallback needs a live Omeka_Db_Select; only its role is tested. */
class TestablePlugin extends CollectionTreeSelectPlugin
{
    public $bypassResult = array(), $bypassCalls = 0, $bypassThrows = false;
    protected function _findPairsBypassingBrowseHook()
    {
        $this->bypassCalls++;
        if ($this->bypassThrows) { throw new Exception('no database here'); }
        return $this->bypassResult;
    }
}

class Probe
{
    public $plugin, $db;
    public function __construct($pairs, $allPairs = array(), $rootsOnly = null)
    {
        $rows = array();
        foreach ($pairs as $id => $parent) {
            $rows[] = array('collection_id' => $id, 'parent_collection_id' => $parent);
        }
        $this->db = new FakeDb($rows, $allPairs, $rootsOnly);
        $this->plugin = new TestablePlugin($this->db);
    }
    public function call($method, $args = array())
    {
        $m = new ReflectionMethod('CollectionTreeSelectPlugin', $method);
        $m->setAccessible(true);
        return $m->invokeArgs($this->plugin, $args);
    }
}

$failures = 0;
function check($name, $actual, $expected)
{
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) { $failures++; }
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . "\n";
    if (!$ok) {
        echo '        expected: ' . json_encode($expected) . "\n";
        echo '        actual:   ' . json_encode($actual) . "\n";
    }
}
function resetOptions()
{
    $GLOBALS['options'] = array(
        'collection_tree_select_colour_roots' => 1,
        'collection_tree_select_full_options' => 1,
        'collection_tree_select_nbsp_per_level' => 4,
    );
}
resetOptions();

// 1 Americas > (4 Brazil, 5 Canada > 8 Ontario); 2 Europe > (6, 7); 3 Zoo.
$TREE = array(1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 1, 6 => 2, 7 => 2, 8 => 5);
$NAMES = array(1=>'Americas',2=>'Europe',3=>'Zoo',4=>'Brazil',5=>'Canada',6=>'France',7=>'Germany',8=>'Ontario');
$TREE_ORDER = array('Americas','Brazil','Canada','Ontario','Europe','France','Germany','Zoo');

function labelled($ids, $extra = array())
{
    global $NAMES;
    $out = $extra;
    foreach ($ids as $id) { $out[$id] = $NAMES[$id]; }
    return $out;
}
function order($o) { return array_values($o); }

echo "---- tree structure ----\n";
$t = (new Probe($TREE))->call('_getTree');
check('depths', $t['depths'], array(1=>0,2=>0,3=>0,4=>1,5=>1,6=>1,7=>1,8=>2));
check('parents', $t['parents'], array(1=>0,2=>0,3=>0,4=>1,5=>1,6=>2,7=>2,8=>5));
check('roots', $t['roots'], array(1=>1,2=>2,3=>3,4=>1,5=>1,6=>2,7=>2,8=>1));

$t3 = (new Probe(array(1=>0, 5=>99, 8=>5)))->call('_getTree');
check('dangling parent becomes a root',
    array($t3['depths'][5], $t3['parents'][5], $t3['roots'][5]), array(0,0,5));
check('its child still hangs off it',
    array($t3['depths'][8], $t3['parents'][8], $t3['roots'][8]), array(1,5,5));

$t4 = (new Probe(array(4=>5, 5=>4)))->call('_getTree');
check('cycle members demoted to roots',
    array($t4['depths'], $t4['parents']), array(array(4=>0,5=>0), array(4=>0,5=>0)));
check('self-parent demoted', (new Probe(array(4=>4)))->call('_getTree'),
    array('depths'=>array(4=>0), 'parents'=>array(4=>0), 'roots'=>array(4=>4)));

$deep = array(1 => 0);
for ($i = 2; $i <= 100; $i++) { $deep[$i] = $i - 1; }
$t6 = (new Probe($deep))->call('_getTree');
check('depth 64 resolved', $t6['depths'][65], 64);
check('past the guard demoted, not hung', array($t6['depths'][66], $t6['parents'][66]), array(0,0));
check('every row present', count($t6['depths']), 100);

echo "\n---- hues ----\n";
$hues = (new Probe($TREE))->call('_getHues');
check('hue shared down a tree',
    $hues[1] === $hues[4] && $hues[4] === $hues[5] && $hues[5] === $hues[8], true);
check('separate trees differ',
    $hues[1] !== $hues[2] && $hues[2] !== $hues[3] && $hues[1] !== $hues[3], true);
check('first root is the origin hue', $hues[1], 210.0);
$hues2 = (new Probe($TREE + array(9 => 7)))->call('_getHues');
check('adding a child keeps hues stable',
    array($hues2[1],$hues2[2],$hues2[3]), array($hues[1],$hues[2],$hues[3]));
check('new child inherits its root hue', $hues2[9], $hues2[2]);
$GLOBALS['options']['collection_tree_select_colour_roots'] = 0;
check('colours off yields an empty map', (new Probe($TREE))->call('_getHues'), array());
resetOptions();

function minGap($n)
{
    $rows = array();
    for ($i = 1; $i <= $n; $i++) { $rows[$i] = 0; }
    $h = array_values((new Probe($rows))->call('_getHues'));
    sort($h);
    $gap = 360 - end($h) + $h[0];
    for ($i = 1; $i < count($h); $i++) { $gap = min($gap, $h[$i] - $h[$i-1]); }
    return $gap;
}
check('8 roots evenly spread', round(minGap(8), 1), 45.0);
check('40 roots keep over half of optimal', minGap(40) > (360/40)/2, true);

echo "\n---- _sortByTree ----\n";
$p = new Probe($TREE);
check('flat becomes tree order',
    order($p->call('_sortByTree', array(labelled(array(1,4,5,2,6,7,8,3))))), $TREE_ORDER);
check('idempotent on tree order',
    order($p->call('_sortByTree', array(labelled(array(1,4,5,8,2,6,7,3))))), $TREE_ORDER);
check('"No Collection" stays in front',
    order($p->call('_sortByTree', array(labelled(array(1,4,5,2,6,7,8,3), array(0=>'No Collection'))))),
    array_merge(array('No Collection'), $TREE_ORDER));
check('child of an absent parent kept',
    order($p->call('_sortByTree', array(labelled(array(1,4,6,3))))),
    array('Americas','Brazil','Zoo','France'));
check('keys survive',
    array_keys($p->call('_sortByTree', array(labelled(array(1,4,5,2,6,7,8,3))))),
    array(1,4,5,8,2,6,7,3));

echo "\n---- restore / version fallback ----\n";
$ALL = labelled(array(1,4,5,2,6,7,8,3));
$ROOTS = labelled(array(1,2,3));

$v22 = new Probe($TREE, $ALL, $ROOTS);
$v22->plugin->bypassResult = array('unused' => 1);
check('2.2: supported opt-out used', array_keys($v22->call('_getAllPairs')), array_keys($ALL));
check('2.2: no fallback', $v22->plugin->bypassCalls, 0);
check('2.2: asked with the documented param',
    $v22->db->collectionTable->lastParams, array('collection_tree_all_collections' => true));

$v21 = new Probe($TREE, $ALL, $ROOTS);
$v21->db->collectionTable->honoursOptOut = false;
$v21->plugin->bypassResult = $ALL;
check('2.1: truncated answer detected', array_keys($v21->call('_getAllPairs')), array_keys($ALL));
check('2.1: fallback ran once', $v21->plugin->bypassCalls, 1);
$v21->call('_getAllPairs');
check('2.1: memoised', $v21->plugin->bypassCalls, 1);

$broken = new Probe($TREE, $ALL, $ROOTS);
$broken->db->collectionTable->honoursOptOut = false;
$broken->plugin->bypassThrows = true;
check('failing fallback degrades', array_keys($broken->call('_getAllPairs')), array_keys($ROOTS));

$done = new Probe($TREE, $ALL);
$done->plugin->captureCollectionsSelectOptions($ALL);
check('a complete list is left alone', $done->call('_restoreStrippedDescendants', array($ALL)), $ALL);
check('and costs no query', $done->db->collectionTable->calls, 0);

$flat = new Probe(array(1=>0, 2=>0), labelled(array(1,2)));
check('flat site left alone',
    $flat->call('_restoreStrippedDescendants', array(labelled(array(1,2)))), labelled(array(1,2)));
check('and costs no query', $flat->db->collectionTable->calls, 0);

$VISIBLE = labelled(array(1,4,2));
$priv = new Probe($TREE, $VISIBLE);
$priv->plugin->captureCollectionsSelectOptions(labelled(array(1,2)));
check('private collections not resurrected',
    array_keys($priv->call('_restoreStrippedDescendants', array(labelled(array(1,2))))),
    array(1,4,2));

$GLOBALS['options']['collection_tree_select_full_options'] = 0;
$off = new Probe($TREE, $ALL);
check('option switches it off', $off->call('_restoreStrippedDescendants', array($ROOTS)), $ROOTS);
resetOptions();

echo "\n---- filter end to end ----\n";
$NB = "\xC2\xA0";
$e2e = new Probe($TREE, $ALL, $ROOTS);
$e2e->db->collectionTable->honoursOptOut = false;
$e2e->plugin->bypassResult = $ALL;
$e2e->plugin->captureCollectionsSelectOptions(array(0 => 'No Collection') + $ROOTS);
check('CollectionTree 2.1 public search: whole tree, ordered and indented',
    array_values($e2e->plugin->filterCollectionsSelectOptions($ROOTS)),
    array('No Collection', 'Americas', $NB.$NB.$NB.$NB.'Brazil', $NB.$NB.$NB.$NB.'Canada',
          str_repeat($NB,8).'Ontario', 'Europe', $NB.$NB.$NB.$NB.'France',
          $NB.$NB.$NB.$NB.'Germany', 'Zoo'));

$hyph = array(1=>'Americas', 4=>'-Brazil', 5=>'-Canada', 8=>'--Ontario');
$p6 = new Probe($TREE, $ALL);
$p6->plugin->captureCollectionsSelectOptions($hyph);
check('hyphen padding replaced, not stacked',
    array_values($p6->plugin->filterCollectionsSelectOptions($hyph)),
    array('Americas', $NB.$NB.$NB.$NB.'Brazil', $NB.$NB.$NB.$NB.'Canada', str_repeat($NB,8).'Ontario'));

$GLOBALS['options']['collection_tree_select_nbsp_per_level'] = 0;
$p7 = new Probe($TREE, $ALL);
$p7->plugin->captureCollectionsSelectOptions($hyph);
check('indent 0 leaves labels but still sorts',
    array_values($p7->plugin->filterCollectionsSelectOptions($hyph)),
    array('Americas','-Brazil','-Canada','--Ontario'));
resetOptions();

check('stripIndent handles mixed padding',
    (new Probe($TREE))->plugin->stripIndent($NB.$NB.'-- Faculty letters'), 'Faculty letters');

echo "\n" . ($failures ? $failures . ' FAILURE(S)' : 'all checks passed') . "\n";
exit($failures ? 1 : 0);
