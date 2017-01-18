<?php

/**
 * Transform wiki tags in html.
 * Will look for all wiki_TAG functions, in all modules, and apply them to the code
 * to convert tags.
 */
function checkCodeTags(&$env, $html, $options = 'U') {
  $replacing = array();
  $regexs = array(
    ('/\{(.*)\}/' . $options) => array('{', '}', '|'),
    ('/\[(.*)\]/' . $options) => array('[', ']', '|'),
  );

  foreach ($regexs as $regex => $delimiters) {
    preg_match_all($regex, $html, $matches);
    foreach ($matches[1] as $tag_value) {
      $tag_full = $delimiters[0] . $tag_value . $delimiters[1];
      $replaces = checkCodeTags($env, $tag_value);
      foreach ($replaces as $tag => $val) {
        $tag_value = str_replace($tag, $val, $tag_value);
      }
      $tag = explode(':', $tag_value);

      // If there is more than one : we have to just consider the first chunk
      // and unify the rest.

      $tag_destination = isset($tag[1]) ? $tag[1] : NULL;
      for ($i = 2; $i < count($tag); $i++) {
        $tag_destination .= ':' . $tag[$i];
      }

      $attributes = explode($delimiters[2], $tag[0]);
      $tag_name = $attributes[0];
      $attr_arr = array();
      unset($attributes[0]);
      // Assign attributes as specified in the tag.
      foreach ($attributes as $attr_item) {
        $split = explode('=', $attr_item);

        // If there is more than one = we have to just consider the first chunk
        // and unify the rest.

        $attribute_name = $split[0];
        $attribute_value = isset($split[1]) ? $split[1] : NULL;
        for ($i = 2; $i < count($split); $i++) {
          $attribute_value .= '=' . $split[$i];
        }

        if (isset($attribute_value)) {
          $attr_arr[$attribute_name] = $attribute_value;
        }
        else {
          $attr_arr[$attribute_name] = TRUE;
        }
      }

      // when the showtag attribute is used, don't render the tab but just display it.
      if (!empty($attr_arr['showtag'])) {
        $replacing[$tag_full] =  string_normalize(str_replace('|showtag', '', $tag_full));
      }
      else {
        if ($tag_name == strtoupper($tag_name) && function_exists('wiki_' . $tag_name)) {
          $func = 'wiki_' . $tag_name;
          if (function_exists($func)) {
            $tag_html = $func($attr_arr, !empty($tag_destination) ? $tag_destination : NULL, $html, $env);
            $wikitag = array($tag_full => $tag_html);
            // Let other module hook into the rendered wiki tag.
            $env->hook('wikitag', array(
              'attributes' => $attr_arr,
							'target' => $tag_destination,
              'wikitag' => &$wikitag
            ));

            $tag_content = $wikitag[$tag_full];

            // Replacement where nowiki attribute present. Used for input forms etc.
            // TODO: maybe should we convert all special chars?
            if (isset($attr_arr['nowiki'])) {
              $tag_content = string_normalize($tag_content);
            }
            $replacing[$tag_full] = $tag_content;

          }
        }
      }
    }
  }
  return $replacing;
}

function transformCodeTags(&$env, $html) {
// After rendering all tags in a page, the result could be containing other tags.
  // For this reason, keep looping until all tags are rendered.
  while (TRUE) {

    $replaces = (checkCodeTags($env, $html));

    if (empty($replaces)) {
      break;
    }
    foreach ($replaces as $k => $replace) {

      $html = str_replace($k, $replace, $html);
    }
  }
  return $html;
}
