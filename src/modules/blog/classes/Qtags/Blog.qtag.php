<?php
namespace Quanta\Qtags;
use Quanta\Common\DirList;

/**
 * Renders a "blog" view, that's showing a pre-set list of nodes
 * with thumbnail, author, last edit date, title, teaser, etc.
 */

class Blog extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $this->setAttribute('sort', 'time');
    $dirlist = new DirList($this->env, $this->getTarget(), 'blog', $this->attributes, 'blog');
    return $dirlist->render();
  }
}
