<?php
namespace Quanta\Qtags;

/**
 * Creates a link to change the author of a node.
 */
class ChangeAuthor extends Link {
  public $link_class = array('change-author-link');
  protected $html_body = '&#9998;';
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    // If no target is specified, use the current Node as a target.
    $nodeobj = \Quanta\Common\NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $this->setTarget($nodeobj->getName());
    // Check if the user has the permission to edit a node.
    if (\Quanta\Common\NodeAccess::check($this->env, \Quanta\Common\Node::NODE_ACTION_EDIT, array('node' => $nodeobj))) {
      return parent::render();
    }
  }
}
