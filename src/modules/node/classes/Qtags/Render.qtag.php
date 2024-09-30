<?php
namespace Quanta\Qtags;

/**
 *
 * Renders the full rendered content of a node, without any additional wrappers.
 *
 */
class Render extends QTag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    // We can't allow an empty target for content, as it would continue looping forever.
    $node = \Quanta\Common\NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $tpl = !empty($this->getAttribute('tpl')) ? $this->getAttribute('tpl') : null;
    $module = !empty($this->getAttribute('module')) ? $this->getAttribute('module') : null;
    if($module){
      $module = $this->env->getModule($module);
      $module =  $module['path'];
    }
    $content = \Quanta\Common\NodeFactory::render($this->env, $node->getName(), null, $tpl, $module);

    return $content;
  }
}
