<?php

/**
 * Detect content BLOG and eventually create HTML.
 */
function wiki_BLOG($attributes, $value, $string, $page) {
  $dirlist = new Blog($page->env, $value, 'blog', array('sortbytime' => 'desc') + $attributes);
  return $dirlist->render();
}