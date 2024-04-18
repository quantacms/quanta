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
      if (empty($this->attributes['tooltip'])) {
        // Default title for a "Add" Link, used for the tooltip. Can be overridden.
        $add_title_default = t('Add to !title...', array('!title' => \Quanta\Common\Api::filter_xss($nodeobj->getTitle())));
        $this->attributes['tooltip'] = !empty($this->attributes['tooltip']) ? \Quanta\Common\Api::filter_xss($this->attributes['tooltip']) : $add_title_default;
      }
      return parent::render();
    }
  }
}
