<?php

/**
 * Detect content JUMPER and eventually create HTML.
 */
function wiki_PRODUCTS($attributes, $value, $string, $page) {

  $dirlist = new ProductList($page->env, $value, 'products', array('sortbytime' => 'asc') + $attributes);
  return $dirlist->render();
}
