<?php
namespace Quanta\Qtags;

/**
 * Renders a simple (translatable) string.
 */

class Text extends Qtag {
  public function build () {
    $this->attributes['cache'] = 'disk';
  }
  /**
   * Render the Qtag.
   *
   * @return text
   *   The rendered Qtag.
   */
  public function render() {
    $tag = !isset($this->attributes['tag']) ? NULL : $this->attributes['tag'];
    $replace_arr = $this->replace($this->getAttribute('replace'));
    return \Quanta\Common\Localization::translatableText($this->env, $this->getTarget(), $tag, null, $replace_arr);
  }

   /**
   * Replace custom charcters in the text.
   *
   * @return Array $replace_arr
   *   What should be replaced.
   */
  private function replace($replace_item){
    $replace_string = $replace_item ? $replace_item : null;
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
    return $replace_arr;
  }
}
