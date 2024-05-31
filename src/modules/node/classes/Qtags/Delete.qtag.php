<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;
use Quanta\Common\NodeAccess;
use Quanta\Common\Api;

/**
 * Creates a link to delete a specific node, if current user has rights to do so.
 */
class Delete extends Link {
  public $link_class = array('delete-link');

  protected $html_body = '&ominus;';
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $nodeobj = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $this->setTarget($nodeobj->getName());

    if (NodeAccess::check($this->env, \Quanta\Common\Node::NODE_ACTION_DELETE, array('node' => $nodeobj))) {

      $this->attributes['redirect'] = isset($this->attributes['redirect']) ? $this->attributes['redirect'] : '';
      return parent::render();
    }
  }
}
