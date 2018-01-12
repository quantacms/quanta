<?php
/**
 * A simple block, boxed and non editable by default.
 * Wiki Hook for BOX.
 */
function wiki_BOX($attributes, $var, $string, $env) {
  if (!isset($attributes['editable'])) {
    $attributes['editable'] = BOX_NON_EDITABLE;
  }
  // TODO: accept sizing variables.
  $box = new Box($env, NULL, NULL, $attributes);
  if (!empty($attributes['box'])) {
    unset($attributes['box']);
  }
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