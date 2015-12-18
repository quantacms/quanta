<?php
/**
 * Wiki Hook for SEARCH.
 */
function wiki_SEARCH($searchfull, $var, $string, $page) {
  $string = '<form action="/search" method="GET" id="search-form"><input type="text" id="search" name="search"/><input type="submit" value="search" onClick="if ($(\'#search\').val().length < 1) {alert(\'Please enter at least TWO characters to search!\'); return false;}"/></form>';
  return $string;
}