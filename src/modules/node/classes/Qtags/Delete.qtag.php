<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;
use Quanta\Common\NodeAccess;
use Quanta\Common\Api;

/**
 * Creates a link to delete a specific node, if user has rights.
 */
class Delete extends Link {
  public $link_class = array('delete-link');

  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $nodeobj = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $this->setTarget($nodeobj->getName());

    if (NodeAccess::check($this->env, \Quanta\Common\Node::NODE_ACTION_DELETE, array('node' => $nodeobj))) {
      $this->attributes['link_class'] = isset($this->attributes['delete_class']) ? $this->attributes['delete_class'] : '';
      $this->attributes['title'] = isset($this->attributes['title']) ? Api::filter_xss($this->attributes['title']) : '&ominus;';
      $this->attributes['tooltip'] = isset($this->attributes['tooltip']) ? Api::filter_xss($this->attributes['tooltip']) : t('Delete !title...', array('!title' => Api::filter_xss($nodeobj->getTitle())));
      $this->attributes['redirect'] = isset($this->attributes['redirect']) ? $this->attributes['redirect'] : '';
    }
    return parent::render();
  }
}
