<?php
namespace Quanta\Qtags;

/**
 * Renders a simple (translatable) string.
 */

class Text extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return text
   *   The rendered Qtag.
   */
  public function render() {
    $tag = !isset($this->attributes['tag']) ? NULL : $this->attributes['tag'];
    $replace_string = $this->getAttribute('replace') ? $this->getAttribute('replace') : null;
    $replace_arr = [];
    if($replace_string){
      // Explode the replace string by semicolon
      $parts = explode(';', $replace_string);
      // Iterate through the parts and create the associative array
      foreach ($parts as $part) {
        // Explode each part by '@' and directly check for exactly two parts
        if (list($key, $value) = explode('@', $part, 2)) {
            $replace_arr[$key] = $value;
        }
      }
    }
    return \Quanta\Common\Localization::translatableText($this->env, $this->getTarget(), $tag, null, $replace_arr);
  }
}
