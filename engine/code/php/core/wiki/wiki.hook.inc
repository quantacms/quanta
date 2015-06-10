<?php
/**
 * Implementation of hook_init.
 * Look for all wiki_TAG functions, in all modules, and apply them to the code
 * to convert tags.
 *
 * @param $vars
 */
function wiki_page_init($vars) {
    // After rendering all tags in a page, the result could be containing other tags.
    // For this reason, keep looping until all tags are rendered.
    while ($vars['page']->html != wikize($vars['env'], $vars['page'])) {
      continue;
    }
}