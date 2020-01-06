<?php
namespace Quanta\Qtags;
use Quanta\Common\FileList;

/**
 * Create a list of files.
 */
class Files extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $filelist = new FileList($this->env, $this->getTarget(), 'file_table', $this->attributes, 'list');
    return $filelist->render();
  }
}
