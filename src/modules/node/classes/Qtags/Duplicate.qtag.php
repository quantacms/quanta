<?php
namespace Quanta\Qtags;

/**
 * Creates a link to duplicate a specific node, if user has rights.
 */
class Duplicate extends Link {
  public $link_class = array('duplicate-link');
  public $language;
  public $widget;
  public $components;

  protected $html_body = '&#128203;';
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $nodeobj = \Quanta\Common\NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $this->setTarget($nodeobj->getName());

    if (\Quanta\Common\NodeAccess::check($this->env, \Quanta\Common\Node::NODE_ACTION_ADD, array('node' => $nodeobj))) {
      $this->attributes['redirect'] = isset($this->attributes['redirect']) ? $this->attributes['redirect'] : '';
      return parent::render();
    }
  }
}
