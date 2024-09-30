<?php
namespace Quanta\Qtags;
use Quanta\Common\DirList;

/**
 * Renders a list of nodes to be used as possible values for a form item.
 */
class ListValues extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $this->attributes['editable'] = 'false';
    $this->attributes['clean'] = TRUE;
    $this->attributes['separator'] = ',';
    if (empty($this->attributes['sort'])) {
      $this->attributes['sort'] = 'title';
    }
    $dirlist = new DirList($this->env, $this->getTarget(), 'list-values', $this->attributes, 'form');
    return $dirlist->render();
  }
}
