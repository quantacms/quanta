<?php
namespace Quanta\Qtags;
use Quanta\Common\PlayList;
/**
 * Render an Album of audio files.
 */
class ListSongs extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $dirlist = new PlayList($this->env, $this->getTarget(), 'playlist', $this->attributes);
    return $dirlist->render();
  }
}
