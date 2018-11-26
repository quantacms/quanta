<?php
namespace Quanta\Common;

/**
 * Class Node
 * This class represents a Node (corrisponding to a folder in the file system).
 * This is the core of the engine.
 */
class NodeTemplate extends DataContainer {
  /** @var Node $node */
  public $node;
  /** @var string $html */
  public $html = '';

  /**
   * Constructs a node template  object.
   * @param Environment $env
   * @param string $name
   * @param string $father
   * @param string $language
   */
  public function __construct(&$env, $node) {
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
   * "wrap" a Node block in html tags allowing inline editing, deleting etc.
   *
   *  @param Node $node
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
    if (NodeAccess::check($env, NODE_ACTION_EDIT, array('node' => $node))) {
      $actions[] = '[EDIT:' . $node->getName() . ']';
    }
    // If user can delete the node, add a delete button.
    if (NodeAccess::check($env, NODE_ACTION_DELETE, array('node' => $node))) {
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
   * Eventually node will use tpl.html files (with dashed depth).
   */
  public function buildTemplate() {

    // The node's default renderis is its content.

    $tpl = array();
    $tpl_level = 0;

    // a template in the same directory has always priority.
    if (is_file($this->node->getPath() . '/tpl.html')) {
      $this->setData('tpl_file', $this->node->getPath() . '/tpl.html');
    }
    else {
      $lineages = $this->node->getLineage();
      foreach ($lineages as $lineage) {

        $tpl_level++;

        // If tpl^.html exists - template applies to all sublevels of the node.
        if (is_file($lineage->path . '/tpl^.html')) {
          $this->setData('tpl_file', $lineage->path . '/tpl^.html');
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

      // check if there is a sub-level template.
      if (isset($tpl[$tpl_level])) {
        $this->setData('tpl_file', $tpl[$tpl_level]);
      }
    }

    // Set the template.
    $this->html = (!empty($this->getData('tpl_file'))) ? file_get_contents($this->getData('tpl_file')) : $this->node->getBody();

  }
}
