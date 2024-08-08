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
    $tpl = !empty($this->getAttribute('tpl')) ? $this->getAttribute('tpl') : 'file_table';
    $module = !empty($this->getAttribute('module')) ? $this->getAttribute('module') : 'list';
    $filelist = new FileList($this->env, $this->getTarget(),$tpl, $this->attributes, $module);
    return $filelist->render();
  }
}
