<?php
namespace Quanta\Common;
/**
 * Class QtagFactory
 *
 * This Factory is used for building qTag objects, transforming and rendering qTags.
 *
 */
class QtagFactory {

  /**
   * Maximum number of substitution passes transformCodeTags() will run before
   * giving up on reaching a fixpoint.
   *
   * A pass corresponds to one level of Qtag nesting: a Qtag that renders to
   * markup containing further Qtags needs one more pass than the markup it
   * came from. Templates nest a handful of levels deep (index.html -> node
   * template -> list template -> row Qtags), so this bound is far above
   * anything legitimate and only fires on a Qtag that renders its own markup.
   */
  const MAX_TRANSFORM_PASSES = 100;

  /**
   * Returned by resolveMarkup() for markup that must be left exactly as it is:
   * a deferred runlast Qtag, or a string that did not parse into a Qtag.
   *
   * Deliberately distinct from NULL, which is what a Qtag that rendered nothing
   * returns and which substitutes an empty string. Contains a NUL byte so no
   * rendered HTML can collide with it.
   */
  const MARKUP_UNRESOLVED = "\0qtag-unresolved";

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
  public static function checkCodeTags(Environment &$env, $html, array $options = array(), $regex_options = 's') {
    $replacing = array();
    foreach (QtagFactory::qtagRegexes($options, $regex_options) as $regex => $delimiters) {
      preg_match_all($regex, $html, $matches);
      foreach ($matches[0] as $tag_full) {
        // A page repeats the same markup constantly, and a Qtag's markup is
        // its entire input: occurrences 2..N of a string resolve to the value
        // $replacing already holds, in the same slot.
        if (isset($replacing[$tag_full])) {
          continue;
        }
        $replace = QtagFactory::resolveMarkup($env, $tag_full, $delimiters, $options);
        if ($replace !== self::MARKUP_UNRESOLVED) {
          $replacing[$tag_full] = $replace;
        }
      }
    }
    return array('replaces' => $replacing);
  }

  /**
   * Build the Qtag-matching regexes for a set of delimiters.
   *
   * The character class excludes BOTH bracket types, so a regex only ever
   * matches an innermost Qtag -- which is what makes nesting work by repeated
   * passes rather than by parsing.
   *
   * @return array
   *   Regex => delimiters (open, close, attribute separator).
   */
  protected static function qtagRegexes(array $options, $regex_options = 's') {
    $regexs = array();
    $qtag_delimiters = isset($options['qtag_delimiters']) ? $options['qtag_delimiters'] : array('[]', '{}');
    foreach ($qtag_delimiters as $qtag_delimiter) {
      $qtag_del_open = substr($qtag_delimiter, 0, 1);
      $qtag_del_close = substr($qtag_delimiter, 1, 1);
      // Default regex option is "greedy".
      $regexs['/\\' . $qtag_del_open . '[A-Z][^\[\]\{\}]+\\' . $qtag_del_close . '/' . $regex_options] = array($qtag_del_open, $qtag_del_close, '|');
    }
    return $regexs;
  }

  /**
   * Resolve one Qtag markup string to the text that should stand in its place.
   *
   * @param Environment $env
   *   The Environment.
   * @param string $tag_full
   *   The full markup, e.g. "[TITLE|x=1:y]".
   * @param array $delimiters
   *   The delimiters the markup was matched with.
   * @param array $options
   *   Transform options; 'runlast' selects the deferred phase.
   *
   * @return mixed
   *   The replacement (string, array, or NULL for a Qtag that rendered
   *   nothing), or self::MARKUP_UNRESOLVED to leave the markup untouched.
   */
  protected static function resolveMarkup(Environment &$env, $tag_full, $delimiters, array $options) {
    // Same markup, already resolved earlier in this request: reuse the outcome
    // without rebuilding anything. Qtag::preload() also caches rendered HTML
    // on this identity, but only once the string has been parsed and an object
    // constructed - memoising here skips both.
    //
    // The bin carries the runlast flag because that option, not the markup,
    // decides whether a runlast Qtag renders or is deferred.
    $memo_bin = empty($options['runlast']) ? 'qtag_markup' : 'qtag_markup_runlast';
    $memo = \Quanta\Common\Cache::get($env, $memo_bin, $tag_full);
    if ($memo !== FALSE) {
      return $memo;
    }

    $qtag = QtagFactory::parseQTag($env, $tag_full, $delimiters);
    if (!$qtag) {
      \Quanta\Common\Cache::set($env, $memo_bin, $tag_full, self::MARKUP_UNRESOLVED);
      return self::MARKUP_UNRESOLVED;
    }

    // The runlast attribute identifies those Qtags that should be rendered only
    // AFTER all the other Qtags have been loaded.
    if (!empty($qtag->getAttribute('runlast')) && empty($options['runlast'])) {
      \Quanta\Common\Cache::set($env, $memo_bin, $tag_full, self::MARKUP_UNRESOLVED);
      return self::MARKUP_UNRESOLVED;
    }
    // Show the Qtag - don't render it.
    elseif (isset($qtag->attributes['showtag'])) {
      $replace = Api::string_normalize(str_replace('|showtag', '', $tag_full));
    }
    // Show the Qtag - don't render it, and highlight it for readability.
    elseif (isset($qtag->attributes['highlight'])) {
      $replace = $qtag->highlight();
    }
    // Replace the Qtag with its rendered HTML.
    else {
      $qtag->preload();
      $replace = $qtag->getHtml();
    }

    // A Qtag that rendered NULL is memoised too, as NULL: it substitutes an
    // empty string, which is a different outcome from MARKUP_UNRESOLVED and
    // must not send the Qtag back through render() on the next pass.
    \Quanta\Common\Cache::set($env, $memo_bin, $tag_full, $replace);
    return $replace;
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
    $transformed = 0;
    $regexs = QtagFactory::qtagRegexes($options);

    // After rendering all Qtags in a page, the result could still contain
    // other Qtags, derived from the first conversion cycle.
    // For this reason, keep looping until all Qtags are rendered.
    while (TRUE) {
      // A Qtag whose rendered output contains its own markup would spin this
      // loop until max_execution_time kills the request and the whole page is
      // lost. Giving up instead renders the page with that markup still
      // visible, which is diagnosable rather than blank.
      if ($transformed >= self::MAX_TRANSFORM_PASSES) {
        new Message($env, t(
          'Qtag rendering did not settle after !max passes. Some Qtags were left unrendered - check for a Qtag whose output contains its own markup.',
          array('!max' => self::MAX_TRANSFORM_PASSES)
        ), Message::MESSAGE_WARNING);
        break;
      }
      $transformed++;

      // Scan and substitute in ONE pass over the subject, per delimiter.
      //
      // Collecting the replacements first and then applying them with a
      // str_replace per distinct Qtag costs O(distinct x subject): the whole
      // page is rebuilt once per Qtag it contains. preg_replace_callback walks
      // the subject once and splices as it goes, so the cost no longer depends
      // on how many distinct Qtags the page has. strtr() batches the same work
      // and is far worse here - it probes every position for a key of each
      // length it knows, and a page carries thousands of distinct markup
      // lengths.
      //
      // The callback fires in scan order, so Qtags render in document order.
      // preg_replace_callback does not re-scan replacement text, so Qtags
      // revealed by a substitution are picked up on the next turn of this
      // loop: that is what makes the fixpoint.
      $changed = FALSE;
      foreach ($regexs as $regex => $delimiters) {
        $result = preg_replace_callback($regex, function ($match) use (&$env, $delimiters, $options, &$changed) {
          $replace = QtagFactory::resolveMarkup($env, $match[0], $delimiters, $options);
          if ($replace === self::MARKUP_UNRESOLVED) {
            // Deferred runlast, or not a Qtag at all: leave the markup alone.
            return $match[0];
          }
          $changed = TRUE;
          if (is_array($replace)) {
            $replace = implode(\Quanta\Common\Environment::GLOBAL_SEPARATOR, $replace);
          }
          return ($replace == NULL) ? '' : $replace;
        }, $html);

        // preg_replace_callback returns NULL on failure (PREG_BACKTRACK_LIMIT
        // and friends). Keeping the subject is the safe outcome: the page
        // renders with markup still in it instead of being silently emptied.
        if ($result !== NULL) {
          $html = $result;
        }
      }

      if (!$changed) {
        break;
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
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param string $tag_full
   *   The full string of the QTag
   *
   * @param $delimiters
   *   The Qtag's delimiters.
   *
   * @return mixed
   *   The HTML with all Qtags transformed.
   */
  public static function parseQTag(Environment $env, $tag_full, $delimiters) {
    $tag_delimited = substr($tag_full, 1, strlen($tag_full) - 2);
    $tag = explode(':', $tag_delimited);
    $tag_name_p = $tag[0];
    if ($tag_name_p == '$') {
      $tag_name_p = 'VARIABLE';
    }
    // If there is more than one : we have to just consider the FIRST chunk
    // and unify the rest.
    if (count($tag) > 1) {
      unset($tag[0]);
      $target = implode(':', $tag);
    }
    else {
      $target = NULL;
    }

    // Load the attributes of the qtag.
    $attributes = explode($delimiters[2], $tag_name_p);
    $tag_name = $attributes[0];
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

    // Parse the qTag.
    $qtag = QtagFactory::buildQTag($env, $tag_name, $qtag_attributes, $target, $delimiters);
    // Hand the object the string it came from, so cacheTag() can key on it.
    $qtag->markup = $tag_full;
    return $qtag;
  }


  /**
   * Build a Qtag object.
   */
  public static function buildQTag($env, $tag, $attributes, $target, $delimiters) {
    // This code is needed to support Qtags in different versions.
    // MY_QTAG or My_Qtag or MyQtag should all be valid ways to use a Qtag
    // and should all point to the MyQtag class.


    $tag_explode = explode('_', $tag);
    $qtag_class = '';
    foreach ($tag_explode as $tag_part) {
      $qtag_class .= strtoupper(substr($tag_part, 0, 1)) . strtolower(substr($tag_part, 1));
    }
    // Namespace the qtag class.
    $qtag_class_ns = "\\Quanta\\Qtags\\" . $qtag_class;

    // Check if the namespaced class exists, and instantiate it.
    if (empty($attributes['highlight']) && empty($attributes['showtag']) && class_exists($qtag_class_ns)) {
      $qtag = new $qtag_class_ns($env, $attributes, $target, $tag);
    }
    else {
      // @deprecated standard Qtag class will become abstract.
      // For now we keep it for backward compatibility with old function approach.
      $qtag = new \Quanta\Qtags\Qtag($env, $attributes, $target, $tag);
    }

    $qtag->delimiters = $delimiters;

    return $qtag;
  }

  /**
   * Convert a standard (class) name of a Qtag to its capitalized counterpart.
   * I.e. BodyClasses => BODY_CLASSES.
   *
   * @param $qtag
   *   The standard (Class) name of the Qtag.
   *
   * @return string
   *   The "capitalized" version of the Qtag.
   */
  public static function capitalizeQtag($qtag) {
    $capitalized = '';
    for ($i = 0; $i < strlen($qtag); $i++) {
      $letter = substr($qtag, $i, 1);
      if ($i != 0 && ctype_upper($letter)) {
        $capitalized .= "_";
      }
      $capitalized .= $letter;
    }
    return strtoupper($capitalized);
  }
}
