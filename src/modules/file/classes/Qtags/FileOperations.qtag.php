<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;

/**
 * Renders a file operations.
 */
class FileOperations extends HtmlTag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node_name = $this->getAttribute('img_node');
    $img_name = $this->getAttribute('img');
    $html = '<div class="file-operation" data-img_node=' . $node_name . ' data-img=' . $img_name . '>' . $this->getTarget() . '</div>';
    $this->html_body = $html;
    return parent::render();
  }
}
