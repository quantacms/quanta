<?php
namespace Quanta\Common;

/**
 * Implements qtag META_DATA.
 *
 * Automatically generated page meta data.
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
function qtag_META_DATA($env, $target, &$attributes) {
  $meta = $env->getData('metadata');
  $metatags = array();

  foreach ($meta as $meta_name => $meta_content) {
    $properties = array();
    foreach ($meta_content as $type => $value) {
      $properties[] = $type . '="' . $value . '"';
    }
    $metatags[] = '<meta name="' . $meta_name . '" ' . str_replace("\n"," ",implode(' ', $properties)) . ' />';
  }

    return implode("\n", $metatags);
}
