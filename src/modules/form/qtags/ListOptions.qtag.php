<?php
namespace Quanta\Qtags;
use Quanta\Common\DirList;

/**
 * Use subnodes of a node as possible options for a select item.
 */
class ListOptions extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $this->attributes['editable'] = 'false';
    $this->attributes['clean'] = TRUE;
    $this->attributes['separator'] = '';
    $dirlist = new DirList($this->env, $this->getTarget(), 'list-options', $this->attributes, 'form');
    return $dirlist->render();
  }
}
