<?php
/**
 * Implementas hook_page_complete()
 *
 * @param $vars
 */
function wiki_page_complete($vars) {
    // After rendering all tags in a page, the result could be containing other tags.
    // For this reason, keep looping until all tags are rendered.
  $wikitags = $vars['page']->getData('wikitags', array());
  foreach ($wikitags as $tag => $replace) {
    $vars['page']->html = str_replace($tag, $replace, $vars['page']->html);
  }

  // TODO: need a definitive way to avoid printing tags. See also filelist and node.
  $vars['page']->html = str_replace('{{{', '[', str_replace('}}}', ']', $vars['page']->html));
}

/**
 * Produce the replacement for a wikitag.
 * @param $vars
 */
function wiki_wikitag($vars) {
  $vars['page']->setData('wikitags', $vars['page']->getData('wikitags', array()) + $vars['wikitag']);
}