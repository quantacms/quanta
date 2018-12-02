<?php
namespace Quanta\Qtags;
use Quanta\Common\DirList;

/**
 * Renders a list of rendered blocks (1st level nodes into selected folder).
 */
class Blocks extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $dirlist = new DirList($this->env, $this->getTarget(), 'blocks', $this->attributes, 'list');
    return $dirlist->render();
  }
}
