<?php
namespace Quanta\Qtags;

/**
 * Creates a link to edit a specific node, if user has rights.
 */
class Edit extends Link {
  public $link_class = array('edit-link');
  public $language;
  public $widget;
  public $components;

  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $nodeobj = \Quanta\Common\NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $this->setTarget($nodeobj->getName());
    if (empty($this->attributes['title']) && empty($this->html_body)) {
      $this->html_body = '&#9998;';
    }

    if (\Quanta\Common\NodeAccess::check($this->env, \Quanta\Common\Node::NODE_ACTION_EDIT, array('node' => $nodeobj))) {
      $this->attributes['redirect'] = isset($this->attributes['redirect']) ? $this->attributes['redirect'] : '';
      return parent::render();
    }
  }
}
