<?php
namespace Quanta\Qtags;
use Quanta\Common\FileList;

/**
 * Render a list of videos.
 */
class Videos extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
  $this->attributes['clean'] = true;
	$this->attributes['empty_message'] = 'Nessuna foto o video inseriti';
	$this->attributes['file_types'] = 'video';
	$filelist = new FileList($this->env, $this->getTarget(), 'videos', $this->attributes, 'media');
  $items = $filelist->getItems();
  return $filelist->render();
  }
}
