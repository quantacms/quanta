<?php
namespace Quanta\Qtags;
use \Quanta\Common\Environment;

/**
 * Get env const.
 */
class EnvConst extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
	public function render() {
    $target = $this->getTarget();
    $constantName = "\Quanta\Common\Environment::$target";
    $value = '';
    if (defined($constantName)) {
      $value = constant($constantName);
    }
   return $value;
  }
}
