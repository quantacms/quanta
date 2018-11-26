<?php
namespace Quanta\Common;

/**
 * @deprecated  From next release (3.1.1).
 * Please change all reference to block
 *
 * Implements BOX qtag.
 *
 * A simple block, boxed and non editable by default.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_BOX($env, $target, &$attributes) {
  // Check if the box is editable.
  if (!isset($attributes['editable'])) {
    $attributes['editable'] = BOX_NON_EDITABLE;
  }
  // TODO: accept sizing variables.
  $box = new Box($env, NULL, NULL, $attributes);
  if (!empty($attributes['box'])) {
    unset($attributes['box']);
  }
  $box->setHtml(qtag_BLOCK($env, $target, $attributes));
  return $box->render();
}

/**
 * Implements qtag_CLOSE.
 *
 * Renders a button to Ajax-close a container.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_CLOSE($env, $target, &$attributes) {
  return '<a class="close-button" href="#' . $target . '">X Close</a>';
}
