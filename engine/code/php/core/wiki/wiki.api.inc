<?php
/**
 * Transform wiki tags in html.
 * Will look for all wiki_TAG functions, in all modules, and apply them to the code
 * to convert tags.
 */
function wikize($env, &$page, $html) {
  $i = 0;
  // We'll store all [...] tags here.
  $wikitags = array();

  preg_match_all("/\[(.*?)]/", $html, $matches);
  foreach ($matches[1] as $tag_value) {
    $tag_full = '[' . $tag_value . ']';
    $tag = explode(':', $tag_value);
    $tag_arr = explode('|', $tag[0]);
    $tag_name = $tag_arr[0];
    $attributes = explode('|', $tag[0]);
    $attr_arr = array();
    unset($attributes[0]);
    // Assign attributes as specified in the tag.
    foreach ($attributes as $attr_item) {
      $split = explode('=', $attr_item);
      if (isset($split[1])) {
        $attr_arr[$split[0]] = $split[1];
      } else {
        $attr_arr[$split[0]] = TRUE;
      }
    }

    if ($tag_name == strtoupper($tag_name) && function_exists('wiki_' . $tag_name)) {
      $func = 'wiki_' . $tag_name;
      $tag_html = $func($attr_arr, isset($tag[1]) ? $tag[1] : NULL, $html, $page);
      $wikitags[$tag_full] = $tag_html;
      $env->hook('wikitag', array('page' => $page, 'wikitag' => array($tag_full => $tag_html)));
    }
    $i++;
  }

  // Render node links.
  preg_match_all("/\[(.+?):(.*?)\]/i", $html, $matches);
  foreach ($matches[1] as $k => $match) {
    $node = new Node($env, $match);
    if ($node->exists) {
      $tag_full = $matches[0][$k];
      $tag_html = '<a class="internal" href="/' . $match . '">' . $matches[2][$k] . '</a>';
      $wikitags[$tag_full] = $tag_html;
      $env->hook('wikitag', array('page' => $page, 'wikitag' => array($tag_full => $tag_html)));
      $i++;
    }
  }

  foreach ($wikitags as $wikitag => $wikicode) {
    $i += wikize($env, $page, $wikicode);
  }

  return $i;
}

// TODO: temporary function for handling tags into the backend.
function unwikize($env, $html) {
  preg_match_all("/\[(.*?)]/", $html, $matches);
  foreach ($matches[1] as $tag_value) {
    $tag_full = '[' . $tag_value . ']';
    $html = str_replace($tag_full, '{{{' . $tag_value . '}}}', $html);
  }
  return $html;
}