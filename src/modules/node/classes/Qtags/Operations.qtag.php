<?php
namespace Quanta\Qtags;

/**
 * Renders ADD - EDIT - DELETE node links altogether.
 */
class Operations extends HtmlTag {
  protected $html_tag = "span";
  protected $html_params = array("class" => "operations");
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $add = new Add($this->env, array(), $this->getTarget());
    $edit = new Edit($this->env, array(), $this->getTarget());
    $delete = new Delete($this->env, array(), $this->getTarget());
    $this->html_body .= $add->getHtml();
    $this->html_body .= $edit->getHtml();
    $this->html_body .= $delete->getHtml();

    if (!empty($this->html_body)) {
      return parent::render();
    }
  }
}
