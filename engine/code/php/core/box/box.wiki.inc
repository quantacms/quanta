<?php

/**
 * Detect content BOXES and eventually create HTML.
 */
function wiki_BOX($attributes, $value, $string, $page) {
  return '<div style="background:red">' . $value . '</div>';
}
