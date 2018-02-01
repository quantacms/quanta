<?php

/**
 * Implements BLOG qtag.
 */
function qtag_BLOG($env, $target, $attributes) {
  $dirlist = new DirList($env, $target, 'blog', array('sortbytime' => 'desc') + $attributes, 'blog');
  return $dirlist->render();
}
