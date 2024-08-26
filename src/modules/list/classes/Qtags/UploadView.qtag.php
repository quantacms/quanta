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
    $this->attributes['class'] = "just-view list file_admin list-file_admin  ui-sortable";
    return parent::render();
  }
}
