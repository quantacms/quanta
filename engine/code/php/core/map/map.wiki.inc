<?php
/**
 * Detect content MAP and eventually create HTML.
 * @param $attributes
 * @param $path
 * @param $string
 * @param $env
 * @return string
 */
function wiki_MAP($attributes, $path, $string, $env) {
  return '<iframe
  width="100%"
  height="450"
  frameborder="0" style="border:0"
  src="https://www.google.com/maps/embed/v1/place?key=AIzaSyCAwsG20SZRbYwRf4-w-bhc8ehq0KhDcm8&q=Moto Guzzi Tiburtina" allowfullscreen>
</iframe>';
}
