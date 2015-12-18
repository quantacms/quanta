<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 05/06/15
 * Time: 10:44
 */
function wiki_DOMAINSEARCH($attributes, $value, $string, $page) {
  $search_html = file_get_contents('contrib/domain/domain.html.inc');

  return $search_html;
}

