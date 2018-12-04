<?php
namespace Quanta\Qtags;
use Quanta\Common\FileObject;
use Quanta\Common\NodeFactory;

/**
 * Renders a "CLOSE" button that, once clicked, destroys a target HTML div via jQuery.
 */

class File extends Qtag {
  /**
   * Renders a specific attribute of a file.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::loadOrCurrent($this->getData('node'));
    $file = new FileObject($this->env, $this->getTarget(), $node, $this->getData('title'));
    // Return the rendered file, if exists.
    if ($file->exists) {
      return $file->render();
    }
    else {
      return NULL;
    }
  }
}
