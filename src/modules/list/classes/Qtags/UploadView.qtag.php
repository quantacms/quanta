<?php

namespace Quanta\Qtags;

/**
 * View the uploaded file.
 */
class UploadView extends HtmlTag {
  protected $html_tag = 'ul';

  /**
   * Render the HtmlTag.
   *
   * @return string
   *   The rendered HtmlTag.
   */
  public function render() {
    $this->attributes['class'] = "delete-action list file_admin list-file_admin  ui-sortable";
    $this->html_params['data-node'] = $this->getAttribute('node');
    return parent::render();
  }
}
