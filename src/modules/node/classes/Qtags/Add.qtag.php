<?php
namespace Quanta\Qtags;

/**
 * Creates a link to add a node inside another node (as a subfolder) if the current User has the rights to do so.
 */
class Add extends Link {
  public $link_class = array('add-link');
  protected $html_body = '&oplus;';
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    // If no target is specified, use the current Node as a target.
    $nodeobj = \Quanta\Common\NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $this->setTarget($nodeobj->getName());
    // Check if the user has the permission to add a node.
    if (\Quanta\Common\NodeAccess::check($this->env, \Quanta\Common\Node::NODE_ACTION_ADD, array('node' => $nodeobj))) {
      return parent::render();
    }
  }
}
