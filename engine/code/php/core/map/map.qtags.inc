<?php
/**
 * Implement MAP qtag.
 *
 * Renders a google map.
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
function qtag_MAP($env, $target, $attributes) {
  // TODO: remove Aldo's key and setup an attribute :-)
  $key = isset($attributes['key']) ? $attributes['key'] : 'AIzaSyCAwsG20SZRbYwRf4-w-bhc8ehq0KhDcm8';
  return '<iframe width="100%" height="100%" frameborder="0" style="border:0"
  src="https://www.google.com/maps/embed/v1/place?key=' . $key . '&q=' . $target . '" allowfullscreen>
</iframe>';
}
