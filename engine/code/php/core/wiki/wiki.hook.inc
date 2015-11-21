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
    $i = 0;
  $fop = fopen('/tmp/tabs' . $i . '.txt', 'w+');
  fwrite($fop, var_export($vars['page']->html, TRUE));
  fclose($fop);

    while ($vars['page']->html != wikize($vars['env'], $vars['page'])) {
      $i++;
      $fop = fopen('/tmp/tabs' . $i . '.txt', 'w+');
      fwrite($fop, var_export($vars['page']->html, TRUE));
      fclose($fop);
      continue;
    }
}