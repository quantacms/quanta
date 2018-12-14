<?php
namespace Quanta\Qtags;
/**
 * Render a Simple HTML tag with content.
 */
class HtmlTag extends Qtag {
  protected $html_tag = 'div';
  protected $html_params = array();
  protected $html_body = NULL;
  protected $html_self_close = FALSE;

  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    // Allow changing the HTML tag with an attribute.
    if (!empty($this->getAttribute('html_tag'))) {
      $this->html_tag = $this->getAttribute('html_tag');
    }
    // Set the body of the html tag.
    if (empty($this->html_body)) {
      $this->html_body = $this->getTarget();
    }
    // Open the HTML tag.
    $html = '<' . $this->html_tag . ' ';
    // Add the attributes.
    foreach ($this->html_params as $param_name => $param_value) {
      $html .= $param_name . ' ';
      if (!empty($param_value)) {
        $html .= '="' . $param_value . '" ';
      }
    }
    // Self closing tag (i.e. <img />).
    if ($this->html_self_close) {
      $html .= " />";
    }
    else {
      $html .= '>';
      $html .= $this->html_body;
      $html .= '</' . $this->html_tag . '>';
    }
    return $html;
  }
}
