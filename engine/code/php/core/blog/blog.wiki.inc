<?php

/**
 * Detect content BLOG and eventually create HTML.
 */
function wiki_BLOG($attributes, $value, $string, $env) {
  $dirlist = new Blog($env, $value, 'blog', array('sortbytime' => 'desc') + $attributes);
  return $dirlist->render();
}