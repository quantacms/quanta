<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 05/06/15
 * Time: 10:44
 */
function wiki_DOMAINSEARCH($replace, $attributes, $value, $string, $page) {
  $search_html = file_get_contents('contrib/domain/domain.html.inc');

  $string = str_replace($replace, $search_html, $string);
  return $string;
}

function wiki_DOMAINCOLLECTIONS($replace, $attributes, $value, $string, $page) {
  $collections = DomainSearch::getCollections($page->env);
  $output = '<div id="collections">';
  foreach ($collections as $collection) {
    $output .= '<div class="collection" id="' . $collection . '.txt">' . $collection . '</div>';
  }
  $output .= '</div>';
  $string = str_replace($replace, $output, $string);
  return $string;
}
