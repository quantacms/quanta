<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;

/**
 * Renders all the categories of a node ("categories" are folders in which node is linked).
 */
class Categories extends HtmlTag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::current($this->env);
    $cats = $node->getCategories($this->getTarget());
    $cat_attributes = array();
    foreach ($cats as $cat) {
      $cat_link = new Link($this->env, $cat_attributes, $cat->getName());
      $this->html .= $cat_link->render();
    }
    return $this->html;

  }
}
