<?php

/**
 * Detect content JUMPER and eventually create HTML.
 */
function wiki_JUMPER($attributes, $value, $string, $env) {

  $dirlist = new Jumper($env, $value, 'jumper', array('sortbytime' => 'asc') + $attributes);
  $ajax = (isset($attributes['ajax'])) ? $attributes['ajax'] : '_self';
  $empty = (isset($attributes['empty'])) ? $attributes['empty'] : '----------';
  $jumper = '<select class="jumper" rel="' . $ajax . '"><option value="' . JUMPER_EMPTY . '">' . $empty . '</option>' . $dirlist->render() . '</select>';
  return $jumper;
}
