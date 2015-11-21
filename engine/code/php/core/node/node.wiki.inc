<?php
/**
 * @param $replace string
 * @param $tagname string
 * @param $attributes string
 * @param $string string
 * @param $page Page
 * @return mixed
 */
function wiki_NODE($replace, $tagname, $var, $string, $page) {
  if ($var == 'content') {
    $string = str_replace($replace, $page->getData('node')->getRawContent(), $string);
  }
  return $string;
}