<?php
namespace Quanta\Qtags;
/**
 * Returns a parameter from the Query String.
 */

class GlobalSeparator extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $html = '';
    $exploded_array = explode(\Quanta\Common\Environment::GLOBAL_SEPARATOR, $this->getTarget());
    $count = count($exploded_array);
    foreach ($exploded_array as $index => $item) {
      $title = ucfirst($item);
      $html .= "[TEXT|tag={$item}:{$title}]";
      if ($index < $count - 1) {
        $html .= ', ';
      }
    }
    return $html;
  }
}
