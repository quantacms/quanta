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
  /** @var string $tpl */
  public $tpl = null;
  /** @var string $module */
  public $module = null;

  /**
   * Constructs a Node Template  object.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param string $node
   *   The node related to the template.
   */
  public function __construct(Environment &$env, $node, $tpl = null, $module = null) {
    $this->env = $env;
    $this->node = $node;
    $this->tpl = $tpl;
    $this->module = $module;
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
   * is_file() / file_exists() on a template candidate, remembered for the rest
   * of the request.
   *
   * buildTemplate() walks the node's lineage probing a fixed ladder of
   * candidate files at every level, and does so for every node rendered on the
   * page. Siblings - the rows of a list - share every ancestor above them and
   * re-ask the identical question for each row, so a shared ancestor's ladder
   * is stat()ed once per row.
   *
   * Templates are files on disk that a request does not create, so an answer
   * cannot go stale within one: no code path writes a template and then
   * renders through it, doctor and the editors work on node documents.
   *
   * @param string $file
   *   The candidate template file.
   *
   * @param bool $regular_file
   *   TRUE to answer is_file() (rejects directories), FALSE for file_exists().
   *
   * @return bool
   *   Whether the candidate is there.
   */
  protected static function templateExists($file, $regular_file = TRUE) {
    static $memo = array();
    $key = ($regular_file ? 'f:' : 'e:') . $file;
    if (!isset($memo[$key])) {
      $memo[$key] = $regular_file ? is_file($file) : file_exists($file);
    }
    return $memo[$key];
  }

  /**
   * Generate the template for displaying the node.
   *
   * Eventually node will use tpl.html files (with dashed depth).
   */
  public function buildTemplate() {
    $tpl = array();
    $tpl_sublevel = 0;
    $this->node->buildLineage();
    $lineages = $this->node->getLineage() + array($this->node->name => $this->node);
    // Invert lineage: from specific to generic.
    $lineages = array_reverse($lineages);
    // Priority 1: custom tpl
    if($this->tpl != null){
      $this->setData('tpl_file', $this->module . '/tpl/' . $this->tpl . '.tpl.php');
    }
    // If the current node has a TPL set use that directly and don't look for others.
    elseif (self::templateExists($this->node->path . '/tpl.html')) {
      $this->setData('tpl_file', $this->node->path . '/tpl.html');
    }
    elseif (self::templateExists($this->env->dir['tpl'] . '/' . $this->node->name . '_tpl.html')) {
    	$this->setData('tpl_file', $this->env->dir['tpl'] . '/' . $this->node->name . '_tpl.html');
    }
    else {
      // Navigate the node's lineage looking for a suitable template.
      foreach ($lineages as $lineage) {

        // Priority 2: tpl with levels (sub-level).
        // Check it only if the folder is an anchestor of current node folder.
        // We support 5 levels of sub-level templates for now.
        // level 1 = tpl-.html
        // level 2 = tpl--.html
        // etc...
        // "node/subnode/tpl-" has priority over "node/tpl--"
        // Only the candidate whose dash count equals the current sublevel can
        // match, so there is exactly one file to probe per lineage level.
        if ($tpl_sublevel > 0 && $tpl_sublevel <= 5) {
          $min = str_repeat('-', $tpl_sublevel);
          $file = $lineage->path . '/tpl' . $min . '.html';
          $file_tpl = $this->env->dir['tpl'] . '/' . $lineage->name . '_tpl' . $min . '.html';
          if (self::templateExists($file, FALSE)) {
            // tpl matches sublevel and distance form current node position: add it!
            $tpl[$tpl_sublevel] = $file;
          }
          elseif (self::templateExists($file_tpl, FALSE)) {
            $tpl[$tpl_sublevel] = $file_tpl;
          }
        }

        // Priority 3: tpl "catch all".
        // If tpl^.html exists - template applies to all sublevels of the node.
        if (!isset($tpl_catch_all) && self::templateExists($lineage->path . '/tpl^.html')) {
          $tpl_catch_all = $lineage->path . '/tpl^.html';
	      }
	      elseif (!isset($tpl_catch_all) && self::templateExists($this->env->dir['tpl'] . '/' . $lineage->name . '_tpl^.html')) {
          $tpl_catch_all = $this->env->dir['tpl'] . '/' . $lineage->name . '_tpl^.html';
	      }

        $tpl_sublevel++;
      }

      // Check if there is a sub-level template.
      if (!empty($tpl)) {
        // Get the closest sub-level tpl to the node (tpl- is better than tpl--).
        $closest_tpl_key = min(array_keys($tpl));
        $closest_tpl = $tpl[$closest_tpl_key];
        $this->setData('tpl_file', $closest_tpl);
      }
      // If no sub-level template find (it has priority) check if there is a catchall template.
      elseif (isset($tpl_catch_all)) {
        $this->setData('tpl_file', $tpl_catch_all);
      }
      else {
      	// TODO: what to do if No template?
      }
    }

    // Set the template. By default, if no template file is found, a node renders its body.
    $this->html = (!empty($this->getData('tpl_file'))) ? file_get_contents($this->getData('tpl_file')) : $this->node->getBody();
    if ($this->html !== null) {
        $this->html = preg_replace("/\[NODEITEM\]/is", Api::string_normalize($this->node->getName()), $this->html);
    }    
    if($this->tpl){
      $this->html = QtagFactory::transformCodeTags($this->env, $this->html);
    }
  }
}
