<?php
namespace Quanta\Qtags;
use Quanta\Common\Album;


/**
 * Render an Album of audio files.
 */
class ListAlbum extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {

    $dirlist = new Album($this->env, $this->getTarget(), 'album', $this->attributes);
    return $dirlist->render();
  }
}
