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

    $view_switcher = '<div class="file-view-switcher" role="group" aria-label="File view mode">'
      . '<button type="button" class="file-view-button is-active" data-file-view="list" aria-pressed="true" title="List view"><span aria-hidden="true">☷</span></button>'
      . '<button type="button" class="file-view-button" data-file-view="icons" aria-pressed="false" title="Icons and preview view"><span aria-hidden="true">▦</span></button>'
      . '</div>';

    return '<div class="file-view-container">' . $view_switcher . $filelist->render() . '</div>';
  }
}