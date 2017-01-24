<?php
/**
 * A simple block, boxed and non editable by default.
 * Wiki Hook for BOX.
 */
function wiki_BOX($attributes, $var, $string, $env) {
  if (!isset($attributes['editable'])) {
    $attributes['editable'] = 'false';
  }
  if (!isset($attributes['box'])) {
    $attributes['box'] = 'true';
  }
  return wiki_BLOCK($attributes, $var, $string, $env);
}

/**
 * Ajax-close a container.
 * Wiki Hook for CLOSE.
 */
function wiki_CLOSE($attributes, $var, $string, $env) {
  return '<a class="close-button" href="#">X Close</a>';
}