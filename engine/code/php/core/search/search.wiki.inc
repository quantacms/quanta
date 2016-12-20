<?php
/**
 * Wiki Hook for SEARCH.
 */
function wiki_SEARCH($attributes, $var, $string, $env) {
  $search_form = '
		[INPUT|name=search|title=|type=string:search-form]
		[INPUT|name=submit|type=submit|value=Search!:search-form]
		[FORM|method=get|action=' . $var . ':search-form]';
  return $search_form;
}

/**
 * Wiki Hook for SEARCH.
 */
function wiki_SEARCHRESULTS($attributes, $var, $string, $env) {
  return search($env);
}

