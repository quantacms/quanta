<?php
namespace Quanta\Common;
define("MATCH_ONLY", 'match_only');
/**
 * Class QtagFactory
 *
 * This Factory is used for building qTag objects, transforming and rendering qTags.
 *
 */
class QtagFactory {

  /**
   * Searches for qtags in html, triggers the qTag function and converts them.
   * Will look for all qtag_TAG functions in modules, and use it to replace the tag
   * with HTML code.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param string $html
   *   The html to analyze.
   *
   * @param array $options
   *   Options for qtags.
   *
   * @param string $regex_options
   *   Options for the regex closure.
   *
   * @return array
   *   All the qtags in the html.
   *
   */
  public static function checkCodeTags(&$env, $html, $options = array(), $regex_options = 'U') {
    $replacing = array();
    // Find all qtags using regular expressions (both { and [ bracket types are valid for now).
    // TODO: allow more qtag open / close characters, for advanced nesting.
    $regexs = array();
    $qtag_delimiters = isset($options['qtag_delimiters']) ? $options['qtag_delimiters'] : array('[]', '{}');
    foreach ($qtag_delimiters as $qtag_delimiter) {
      $qtag_del_open = substr($qtag_delimiter, 0, 1);
      $qtag_del_close = substr($qtag_delimiter, 1, 1);

      // Default regex option is "greedy".
      $regexs['/\\' . $qtag_del_open . '[A-Z](.*)\\' . $qtag_del_close . '/' . $regex_options] = array($qtag_del_open, $qtag_del_close, '|');
    }

    // Run the regular expression: find all the [QTAGS] in the page.
    foreach ($regexs as $regex => $delimiters) {
      preg_match_all($regex, $html, $matches);

      // If we just want to retrieve the matches without parsing them, return.
      if (isset($options[MATCH_ONLY])) {
        return $matches;
      }

      // Cycle the Qtags.
      foreach ($matches[0] as $tag_full) {
        $tag_undelimited = substr($tag_full, 1, strlen($tag_full) - 2);

        // Runlast.
        if (strpos($tag_undelimited, '{') > 0) {
          continue;
        }
        $qtag = QtagFactory::parseQtag($env, $tag_full, $delimiters);

        if (!empty($qtag->getData('runlast')) && empty($options['runlast'])) {
          continue;
        }
        elseif (count(self::checkCodeTags($env, $tag_undelimited, array('match_only')))) {
          continue;
        }
        else {
          $replacing[$tag_full] = $qtag->render();
        }
      }
    }
    return $replacing;
  }

  /**
   * Replace all the Qtags in the page into their HTML equivalent.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param string $html
   *   The current HTML
   *
   * @param array $options
   *   Other options.
   *
   * @return mixed
   *   The HTML with all Qtags transformed.
   */
  public static function transformCodeTags(&$env, $html, $options = array()) {
    // After rendering all Qtags in a page, the result could still contain
    // other Qtags, derived from the first conversion cycle.
    // For this reason, keep looping until all Qtags are rendered.
    while (TRUE) {
      // Parse all the Qtags in the given html.
      $replaces = (QtagFactory::checkCodeTags($env, $html, $options));

      if (empty($replaces)) {
        break;
      }

      // Do all the Qtag replacements in the given html.
      foreach ($replaces as $qtag => $replace) {
        if (is_array($replace)) {
          $replace = implode(GLOBAL_SEPARATOR, $replace);
        }
        $html = str_replace($qtag, $replace, $html);
      }

    }
    return $html;
  }


  /**
   * Remove all qtags elements from the string (all [elements] within brackets).
   *
   * @param string $string
   *   The string to be stripped.
   *
   * @return string
   *   The stripped string.
   */
  public static function stripQTags($string) {
    return preg_replace('/\[.*?\]/', '', $string);
  }

  /**
   * Parse a Qtag string and build a Qtag object.
   */
  public static function parseQTag($env, $tag_full, $delimiters) {

    $tag_delimited = substr($tag_full, 1, strlen($tag_full) - 2);
    $tag = explode(':', $tag_delimited);
    $tag_name_p = $tag[0];
    // If there is more than one : we have to just consider the LAST chunk
    // and unify the rest.
    $target = (count($tag) > 1) ? $tag[count($tag) - 1] : NULL;
    for ($i = 0; $i < (count($tag) - 1); $i++) {
      $tag_name_p .= ':' . $tag[$i];
    }
    // Load the attributes of the qtag.
    $attributes = explode($delimiters[2], $tag_name_p);

    $tag_name = (count($attributes) > 1) ? $attributes[0] : $tag[0];
    $qtag_attributes = array();
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
        $qtag_attributes[$attribute_name] = $attribute_value;
      }
      else {
        $qtag_attributes[$attribute_name] = TRUE;
      }
    }
    $qtag_attributes['delimiters'] = $delimiters;
    $qtag_attributes['tag_full'] = $tag_full;
    // Parse the qTag.
    $qtag = QtagFactory::buildQTag($env, $tag_name, $qtag_attributes, $target);

    return $qtag;
  }


  /**
   * Build a Qtag object.
   */
  public static function buildQTag($env, $tag, $attributes, $target) {
    $qtagn = "\\Quanta\\Qtags\\" . $tag;
    if (class_exists($qtagn)) {
      $qtag = new $qtagn($env, $tag, $attributes, $target);
    }
    else {
      // @deprecated standard Qtag class will become abstract.
      // For now we keep it for backward compatibility with old function approach.
      $qtag = new \Quanta\Qtags\Qtag($env, $tag, $attributes, $target);
    }
    return $qtag;
  }


}
