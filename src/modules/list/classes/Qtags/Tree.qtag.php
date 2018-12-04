<?php
namespace Quanta\Qtags;
use Quanta\Common\DirList;

/**
 * Renders a directory tree (a list, that goes deep into folders).
 */
class Tree extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $dirlist = new DirList($this->env, $this->getTarget(), 'tree', $this->attributes);
    $render = $dirlist->render();
    return $render;
  }
}
