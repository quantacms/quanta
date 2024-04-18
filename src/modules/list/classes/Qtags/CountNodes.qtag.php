<?php
namespace Quanta\Qtags;
use Quanta\Common\DirList;
use Quanta\Common\NodeFactory;

/**
 * @deprecated could be not safe, or not optimal.
 *
 * Count the number of subdirectories of a given node.
 *
 */
class CountNodes extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {

    $nodeobj = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $depth = '';
    $dirlist = new DirList($this->env, $nodeobj->getName(), 'dir', $this->getAttributes(), 'list');
    return($dirlist->countItems());

  }
}
