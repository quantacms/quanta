<?php
namespace Quanta\Qtags;
use Quanta\Common\FileList;

/**
 * Create a list of files rendered in a table.
 */
class FileTable extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $filelist = new FileList($this->env, $this->getTarget(), 'file', $this->attributes);
    return $filelist->render();
  }
}
