<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;
use Quanta\Common\NodeAccess;
use Quanta\Common\Api;

/**
 * Creates a link to edit a specific node, if user has rights.
 */
class Edit extends Link {
  public $link_class = array('edit-link');

  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $nodeobj = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $this->setTarget($nodeobj->getName());

    if (NodeAccess::check($this->env, \Quanta\Common\Node::NODE_ACTION_EDIT, array('node' => $nodeobj))) {
      $title = Api::filter_xss(empty($nodeobj->getTitle()) ? $nodeobj->getName() : $nodeobj->getTitle());
      $this->attributes['tooltip'] = isset($this->attributes['tooltip']) ? Api::filter_xss($this->attributes['tooltip']) : t('Edit !title...', array('!title' => $title));
      $this->attributes['title'] = isset($this->attributes['title']) ? Api::filter_xss($this->attributes['title']) : '&#9998;';
      $this->attributes['redirect'] = isset($this->attributes['redirect']) ? $this->attributes['redirect'] : '';
      return parent::render();
    }
  }
}
