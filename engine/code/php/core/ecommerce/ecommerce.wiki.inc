<?php

/**
 * Detect content JUMPER and eventually create HTML.
 */
function wiki_PRODUCTS($attributes, $value, $string, $env) {

  $dirlist = new ProductList($env, $value, 'products', array('sortbytime' => 'asc') + $attributes);
  return $dirlist->render();
}
