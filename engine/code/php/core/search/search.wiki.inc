<?php
/**
 * Wiki Hook for SEARCH.
 */
function wiki_SEARCH($replace, $searchfull, $var, $string, $page) {
  $string = str_replace($replace, '<form action="/search" method="GET" id="search-form"><input type="text" id="search" name="search"/><input type="submit" value="search" onClick="if ($(\'#search\').val().length < 1) {alert(\'Please enter at least TWO characters to search!\'); return false;}"/></form>', $string);
  return $string;
}