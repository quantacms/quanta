<?php

namespace Quanta\Common;

/**
 * Class Node
 * This class represents a HTML Template of a Node.
 * It interacts with the way the node is displayed in the page.
 */
class NodeTemplate extends DataContainer {
  /** @var Node $node */
  public $node;
  /** @var string $html */
  public $html = '';

  /**
   * Constructs a Node Template  object.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param string $node
   *   The node related to the template.
   */
  public function __construct(Environment &$env, $node) {
    $this->env = $env;
    $this->node = $node;
    $this->buildTemplate();
  }

  /**
   * Returns the rendered html of the node.
   *
   * @return string
   *   The rendered html.
   */
  public function getHtml() {
    return $this->html;
  }

  /**
   * "Wwrap" a Node block in special html tags allowing inline editing, deleting etc.
   *
   * @param Node $node
   *   The initial html.
   *
   * @param string $html
   *   The initial html.
   *
   * @return string
   *   The wrapped html.
   */
  public static function wrap($env, $node, $html) {
    $actions = array();
    // If user can edit the node, add an edit button.
    if (NodeAccess::check($env, \Quanta\Common\Node::NODE_ACTION_EDIT, array('node' => $node))) {
      $actions[] = '[EDIT:' . $node->getName() . ']';
    }
    // If user can delete the node, add a delete button.
    if (NodeAccess::check($env, \Quanta\Common\Node::NODE_ACTION_DELETE, array('node' => $node))) {
      $actions[] = '[DELETE:' . $node->getName() . ']';
    }

    // TODO: it produces a space before the name. Why?
    $wrap = '<div class="node-item" data-rel="' . $node->getName() . '">';
    if (!empty($actions)) {
      $wrap .= '<div class="node-item-actions">' . implode($actions) . '</div>';
    }
    $wrap .= $html;
    $wrap .= '</div>';
    return $wrap;
  }

  /**
   * Generate the template for displaying the node.
   *
   * Eventually node will use tpl.html files (with dashed depth).
   */
  public function buildTemplate() {
    $tpl = array();
    $tpl_level = 0;
    // TODO: current node taken out of lineage. Correct to add like this?
    $lineages = array($this->node) + $this->node->getLineage();
    if (is_file($this->node->path . '/tpl.html')) {
      $this->setData('tpl_file', $this->node->path . '/tpl.html');
    }
    else {
      foreach ($lineages as $lineage) {
        $tpl_level++;
        // If tpl^.html exists - template applies to all sublevels of the node.
        if (is_file($lineage->path . '/tpl^.html')) {
          $this->setData('tpl_file', $lineage->path . '/tpl^.html');
          break;
        }
        else {
          $min = '';
          // We support 5 levels of sub-level templates for now.
          // level 0 = tpl.html
          // level 1 = tpl-.html
          // level 2 = tpl--.html
          // etc...
          for ($i = 1; $i <= 6; $i++) {
            $min .= '-';
            $file = $lineage->path . '/tpl' . $min . '.html';
            if (file_exists($file)) {
              $tpl[$tpl_level + $i] = $file;
            }
          }
        }
      }
    }
    // Check if there is a sub-level template.
    if (isset($tpl[$tpl_level])) {
      $this->setData('tpl_file', $tpl[$tpl_level]);
    }


    // Set the template. By default, if no template file is found, a node renders its body.
    $this->html = (!empty($this->getData('tpl_file'))) ? file_get_contents($this->getData('tpl_file')) : $this->node->getBody();

  }
}
