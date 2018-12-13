<?php
namespace Quanta\Qtags;

/**
 * Create a Gallery using all images contained in a node.
 */
class Gallery extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $this->attributes['file_types'] = 'image';
    $filelist = new FileList($this->env, $this->getTarget(), 'gallery', $this->attributes, 'gallery');
    $output = $filelist->render();
    return $output;
  }
}
