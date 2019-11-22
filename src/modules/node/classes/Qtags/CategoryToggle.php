<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;

/**
 * Creates a link to add/remove a node from a given category.
 */
class CategoryToggle extends Link {
  public $link_class = array('category-toggle');
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    // If no target is specified, use the current Node as a target.
    $category = \Quanta\Common\NodeFactory::load($this->env, $this->getTarget());
    $node = \Quanta\Common\NodeFactory::loadOrCurrent($this->env, $this->getAttribute('node'));

    $can_toggle = \Quanta\Common\NodeAccess::check($this->env, \Quanta\Common\Node::NODE_ACTION_ADD, array('node' => $category));
    // Toggle is triggered.
    if ($can_toggle && $this->hasAttribute('toggle')) {
      if (!$category->hasChild($node->getName())) {
        NodeFactory::linkNodes($this->env, $node->getName(), $category->getName());
      }
      else {
        NodeFactory::unlinkNodes($this->env, $node->getName(), $category->getName());
      }
    }

    $add_title = empty($this->getAttribute('add_title')) ?
      t('&star; Add in !title', array('!title' => $category->getTitle()))
      : $this->getAttribute('add_title');

    $remove_title = empty($this->getAttribute('remove_title')) ?
      t('&star; Remove from !title', array('!title' => $category->getTitle()))
      : $this->getAttribute('remove_title');

    if (!$category->hasChild($node->getName())) {
      $this->setAttribute('title', $add_title);
      $this->addClass('category-toggle-add');
    }
    else {
      $this->setAttribute('title', $remove_title);
      $this->addClass('category-toggle-remove');
    }

    $this->html_params['data-add-title'] = urlencode($add_title);
    $this->html_params['data-remove-title'] = urlencode($remove_title);
    $this->html_params['data-node'] = $node->getName();
    $this->html_params['data-category'] = $category->getName();

    // Check if the user has the permission to add the node in the category.
    if ($can_toggle) {
      return parent::render();
    }
  }
}
