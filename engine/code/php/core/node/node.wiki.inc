<?php
/**
 * @param $replace string
 * @param $tagname string
 * @param $attributes string
 * @param $string string
 * @param $page Page
 * @return mixed
 */
function wiki_NODE($tagname, $var, $string, $page) {
  if ($var == 'content') {
    return $page->getData('node')->getRawContent();
  }
  // TODO what happens here?!
}