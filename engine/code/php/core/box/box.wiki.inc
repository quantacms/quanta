<?php

/**
 * Ajax-close a container.
 * Wiki Hook for CLOSE.
 */
function wiki_CLOSE($attributes, $var, $string, $env) {
  return '<a class="close-button" href="#">X Close</a>';
}