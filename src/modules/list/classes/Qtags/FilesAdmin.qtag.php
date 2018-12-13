<?php
namespace Quanta\Qtags;
use Quanta\Common\FileList;

/**
 * Create a list of files.
 */
class FilesAdmin extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $filelist = new FileList($this->env, $this->getTarget(), 'file_admin', $this->attributes);

    // TODO: not optimal, but we don't want default files from father node on node add...
    if ($this->env->getContext() == \Quanta\Common\Node::NODE_ACTION_ADD) {
      $filelist->clear();
    }
    else {
      $filelist->generate();
    }

    return $filelist->render();
  }
}
