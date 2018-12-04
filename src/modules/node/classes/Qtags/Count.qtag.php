<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;

/**
 * @deprecated could be not safe, or not optimal.
 *
 * Count the number of subdirectories of a given node.
 *
 */
class Count extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {

    $nodeobj = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $depth = '';
    if (isset($this->attributes['maxdepth'])) {
      $depth .= ' -maxdepth ' . $this->attributes['maxdepth'];
    }
    if (isset($this->attributes['mindepth'])) {
      $depth .= ' -mindepth ' . $this->attributes['mindepth'];
    }
    else {
      $depth .= ' -mindepth 1';
    }
    // TODO: is this safe?
    $count_cmd = 'find ' . $nodeobj->path . ' ' . $depth . ' -type d | wc -l';
    exec($count_cmd, $results_arr);
    return array_pop($results_arr);
  }
}
