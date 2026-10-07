<div class="field">
    <div class="two columns alpha">
        <label for="collection_tree_select_enable_js"><?php echo __('Enable tree picker'); ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Replaces the plain Collection dropdowns in the admin '
            . 'interface with a searchable, indented tree. When off, the dropdowns stay native and '
            . 'only the indentation is applied.'); ?></p>
        <?php echo $this->formCheckbox(
            'collection_tree_select_enable_js',
            null,
            array('checked' => (bool) get_option('collection_tree_select_enable_js')),
            array('1', '0')
        ); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label for="collection_tree_select_colour_roots"><?php echo __('Colour-code trees'); ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Gives each top-level collection a colour and tints every '
            . 'collection below it to match, so a whole tree reads as one group in the picker. '
            . 'Requires the tree picker.'); ?></p>
        <?php echo $this->formCheckbox(
            'collection_tree_select_colour_roots',
            null,
            array('checked' => (bool) get_option('collection_tree_select_colour_roots')),
            array('1', '0')
        ); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label for="collection_tree_select_hue_origin"><?php echo __('Starting colour'); ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Hue given to the first tree, in degrees around the colour '
            . 'wheel: 0 red, 60 yellow, 120 green, 180 cyan, 210 blue (the default), 300 magenta. The '
            . 'remaining trees are spaced out from it automatically. Change this to bring the palette '
            . 'closer to your site\'s own colours.'); ?></p>
        <?php echo $this->formText(
            'collection_tree_select_hue_origin',
            get_option('collection_tree_select_hue_origin'),
            array('size' => 5, 'type' => 'number', 'min' => 0, 'max' => 359)
        ); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label for="collection_tree_select_colour_intensity"><?php echo __('Colour intensity'); ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('How strong the tints are, from 0 for barely-there to 100 '
            . 'for vivid. 50 is the default. Lower it if the colours compete with your interface; raise '
            . 'it if they are too subtle to tell apart. The coloured edge stripe stays legible at any '
            . 'setting.'); ?></p>
        <?php echo $this->formText(
            'collection_tree_select_colour_intensity',
            get_option('collection_tree_select_colour_intensity'),
            array('size' => 5, 'type' => 'number', 'min' => 0, 'max' => 100)
        ); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label for="collection_tree_select_colour_tree_page"><?php echo __('Colour the Collection Tree page'); ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Applies the same colours and indentation to Collection '
            . 'Tree\'s own tree page, and to the tree shown on a collection\'s page, so the list and '
            . 'the dropdowns read as one scheme. Requires colour-coding.'); ?></p>
        <?php echo $this->formCheckbox(
            'collection_tree_select_colour_tree_page',
            null,
            array('checked' => (bool) get_option('collection_tree_select_colour_tree_page')),
            array('1', '0')
        ); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label for="collection_tree_select_full_options"><?php echo __('Full collection list in dropdowns'); ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Collection Tree\'s "browse only root collections" setting '
            . 'also strips subcollections out of the public advanced search dropdown, leaving only '
            . 'top-level collections to search in. This puts them back. Collections a visitor is not '
            . 'allowed to see stay hidden either way.'); ?></p>
        <?php echo $this->formCheckbox(
            'collection_tree_select_full_options',
            null,
            array('checked' => (bool) get_option('collection_tree_select_full_options')),
            array('1', '0')
        ); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label for="collection_tree_select_public"><?php echo __('Apply to the public site'); ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Off by default. When on, the tree picker, the colours and '
            . 'the tree-page layout are applied to the public theme as well as the admin interface. '
            . 'Check your theme afterwards: the tree page is re-laid out as full-width rows.'); ?></p>
        <?php echo $this->formCheckbox(
            'collection_tree_select_public',
            null,
            array('checked' => (bool) get_option('collection_tree_select_public')),
            array('1', '0')
        ); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label for="collection_tree_select_indent"><?php echo __('Indent per level (px)'); ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Horizontal offset applied to each level of nesting in the tree picker.'); ?></p>
        <?php echo $this->formText(
            'collection_tree_select_indent',
            get_option('collection_tree_select_indent'),
            array('size' => 5)
        ); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label for="collection_tree_select_min_search"><?php echo __('Show search box from'); ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Minimum number of collections before the tree picker offers a '
            . 'search box. Set to 0 to always show it.'); ?></p>
        <?php echo $this->formText(
            'collection_tree_select_min_search',
            get_option('collection_tree_select_min_search'),
            array('size' => 5)
        ); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label for="collection_tree_select_nbsp_per_level"><?php echo __('Plain-select indent'); ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Number of non-breaking spaces per nesting level in the plain '
            . 'dropdown, used when the tree picker is off or JavaScript is unavailable.'); ?></p>
        <?php echo $this->formText(
            'collection_tree_select_nbsp_per_level',
            get_option('collection_tree_select_nbsp_per_level'),
            array('size' => 5)
        ); ?>
    </div>
</div>
