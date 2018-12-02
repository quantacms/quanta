<?php
namespace Quanta\Qtags;
require_once 'Link.qtag.php';
use Quanta\Common\NodeFactory;
use Quanta\Common\NodeAccess;
use Quanta\Common\Localization;
use Quanta\Common\Api;

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
    $nodeobj = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $this->setTarget($nodeobj->getName());
    // Check if the user has the permission to add a node.
    if (NodeAccess::check($this->env, NODE_ACTION_ADD, array('node' => $nodeobj))) {
      $this->tooltip = !empty($this->attributes['tooltip']) ? Api::filter_xss($this->attributes['tooltip']) : 'Add to ' . Api::filter_xss($nodeobj->getTitle()) . '...';
      $this->language = !empty($this->attributes['language']) ? $this->attributes['language'] : Localization::getLanguage($this->env);
      $this->link_body = !empty($this->attributes['title']) ? Api::filter_xss($this->attributes['title']) : '&oplus;';
    }
    return parent::render();
  }
}
