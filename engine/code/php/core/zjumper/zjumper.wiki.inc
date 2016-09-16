<?php

/**
 * Detect content JUMPER and eventually create HTML.
 */
function wiki_JUMPER($attributes, $value, $string, $page) {

  $dirlist = new Jumper($page->env, $value, 'jumper', array('sortbytime' => 'asc') + $attributes);
  $ajax = (isset($attributes['ajax'])) ? $attributes['ajax'] : '_self';
  $jumper = '<select class="jumper" rel="' . $ajax . '"><option>--------</option>' . $dirlist->render() . '</select>';
  return $jumper;
}
