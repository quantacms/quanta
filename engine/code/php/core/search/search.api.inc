<?php
define("SEARCH_OUTPUT_MATCH", 'match');
define("SEARCH_OUTPUT_FILENAME", 'filename');
define("SEARCH_OUTPUT_MATCH_FILENAME", 'match_filename');

function search($env, $search_string = NULL, $search_in_node = NULL, $search_fields = array('body'), $search_output = SEARCH_OUTPUT_MATCH_FILENAME) {
  $search_dir = ($search_in_node == NULL) ? $env->dir['docroot'] : $search_in_node->realpath;
  $results_arr = array();
  if (empty($search_string)) {
    $search_string = $_REQUEST['search'];
  }
  $grep_params = array('-R', '-i', '-m100');

  if ($search_output == SEARCH_OUTPUT_MATCH || $search_output == SEARCH_OUTPUT_MATCH_FILENAME) {
    $grep_params[] = '-o';
  }
  $search_arr = array();
  foreach ($search_fields as $search_field) {
    $search_arr[] = $search_field . '\"\:\".*' . $search_string . '.*\"' ;

  }
  // TODO: VERY insecure!
  $search_cmd = "grep " . implode(' ', $grep_params) . "  --include \*.json  '" . implode('|', $search_arr) . "' " . $search_dir;
  // $search_cmd = "find " . $page['dir']['docroot'] . " -iname=\"*.html\" -exec grep -r -i -o -m100 -P  '.{0,140}" . $_REQUEST['search'] . ".{0,140}' {} \; ";
  // Command with find: find "/Applications/MAMP/htdocs/websmenu"  -iname "*.html" -exec grep -r -i -o -m100  '.\{0,140\}mente.\{0,140\}' {} \;

  exec($search_cmd, $results_arr);
  return $results_arr;
}
