<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;

/**
 * Renders the full breadcrumb / lineage of the current node.
 */
class Breadcrumb extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::current($this->env);

    // Check if current node id home (main node).
    if ($node->getName() == 'home'){
      // Do not show breadcrumb in homepage.
      return '';
    }

    $node_home = NodeFactory::load($this->env, 'home');
    // Builds the lineage of the node.
    $node->buildLineage();
    // Starts with home node.
    $breadcrumb = array('home' => $node_home) + $node->getLineage();

    if (empty($this->attributes['include_current'])) {
      array_pop($breadcrumb);
    }

    // TODO: breadcrumb generation must be done in page.class.
    $this->env->setData('breadcrumb', $breadcrumb);
    // Theme and renders the breadcrumb.
    $themed_bc = '<ul class="breadcrumb">';
    if (count($breadcrumb) > 0 && $breadcrumb != '') {
      foreach ($breadcrumb as $i => $node) {
        // Add only published nodes without "breadcrumb_exclude".
        if ($node->isPublished() && !$node->getAttributeJSON('breadcrumb_exclude')) {
          $link_attr = array('link_class' => 'breadcrumb-link');

          $link = new Link($this->env, $link_attr, $node->getName());
          $themed_bc .= '<li>' . $link->render() . '</li>';
        }
      }
    }
    $themed_bc .= '</ul>';
    return $themed_bc;
  }
}
