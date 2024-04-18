<?php
namespace Quanta\Qtags;
use Quanta\Common\DirList;

/**
 * Renders a list of nodes (children of a given node).
 */
class ListNodes extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $tpl = isset($this->attributes['tpl']) ? $this->attributes['tpl'] : 'dir';
    $dirlist = new DirList($this->env, $this->getTarget(), $tpl, $this->getAttributes(), 'list');
    $render = $dirlist->render();
    return $render;
  }
}
