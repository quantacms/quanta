<?php
namespace Quanta\Qtags;

/**
 * Creates a link to add a node inside another node (as a child) if user has the rights.
 */
class Add extends Link {
  public $link_class = array('add-link');
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $nodeobj = \Quanta\Common\NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $this->setTarget($nodeobj->getName());
    // Check if the user has the permission to add a node.
    if (\Quanta\Common\NodeAccess::check($this->env, \Quanta\Common\Node::NODE_ACTION_ADD, array('node' => $nodeobj))) {
      $this->attributes['tooltip'] = !empty($this->attributes['tooltip']) ? \Quanta\Common\Api::filter_xss($this->attributes['tooltip']) : 'Add to ' . \Quanta\Common\Api::filter_xss($nodeobj->getTitle()) . '...';
      $this->language = !empty($this->attributes['language']) ? $this->attributes['language'] : \Quanta\Common\Localization::getLanguage($this->env);
      $this->link_body = !empty($this->attributes['title']) ? \Quanta\Common\Api::filter_xss($this->attributes['title']) : '&oplus;';
      return parent::render();
    }
  }
}
