<?php
define("JUMPER_EMPTY", "_empty");

/**
 *
 * Create a jumper to quick access nodes.
 */
function qtag_JUMPER($env, $target, $attributes) {
  // Which folder to use.
  $dirlist = new DirList($env, $target, 'jumper', array('sortbytime' => 'asc') + $attributes, 'jumper');

  $ajax = (isset($attributes['ajax'])) ? $attributes['ajax'] : '_self';
  $empty = (isset($attributes['empty'])) ? $attributes['empty'] : '----------';
  $tpl = (isset($attributes['tpl'])) ? ('tpl="' . $attributes['tpl'] . '"') : '';

  // Render the jumper.
  // TODO: maybe use FORM tags?
  $jumper = '<select class="jumper" rel="' . $ajax . '" ' . $tpl . '><option value="' . JUMPER_EMPTY . '">' . $empty . '</option>' . $dirlist->render() . '</select>';

  return $jumper;
}
