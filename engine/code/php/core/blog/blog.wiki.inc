<?php

/**
 * Detect content BLOG and eventually create HTML.
 */
function wiki_BLOG($attributes, $value, $string, $env) {
  $dirlist = new DirList($env, $value, 'blog', array('sortbytime' => 'desc') + $attributes, 'blog');
  return $dirlist->render();
}
