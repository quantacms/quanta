<?php
/**
 * A simple block, boxed and non editable by default.
 * Wiki Hook for BOX.
 */
function wiki_BOX($attributes, $var, $string, $env) {
  if (!isset($attributes['editable'])) {
    $attributes['editable'] = BOX_NON_EDITABLE;
  }

  $box = new Box($env, NULL, NULL, $attributes);
  $box->setHtml(wiki_BLOCK($attributes, $var, $string, $env));
  return $box->render();
}

/**
 * Ajax-close a container.
 * Wiki Hook for CLOSE.
 */
function wiki_CLOSE($attributes, $var, $string, $env) {
  return '<a class="close-button" href="#">X Close</a>';
}