<?php
namespace Quanta\Qtags;
use Quanta\Common\FileList;

/**
 * Get env data.
 */
class Env extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
	public function render() {

        return $this->env->getData($this->getAttribute('key'));
	
  }
}
