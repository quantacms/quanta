<?php
namespace Quanta\Qtags;
/**
 * Returns a sepreated text.
 */

class Separate extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $html = '';
    $separator = $this->getAttribute('separator');
    $is_for_filter = $this->getAttribute('for-filter');
    $exploded_array = explode($separator, $this->getTarget());
    $count = count($exploded_array);
    foreach ($exploded_array as $index => $item) {
      if($is_for_filter){
        $html .= $item;
      }
      else{
        $title = ucfirst($item);
        $html .= "[TEXT|tag={$item}:{$title}]";
      }
     
      if ($index < $count - 1) {
        $html .= $is_for_filter ? ';' : ', ';
      }
    }
    return $html;
  }
}
