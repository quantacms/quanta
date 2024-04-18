<?php
namespace Quanta\Qtags;

/**
 * Render a playable video file.
 */
class Video extends HtmlTag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public $html_tag = 'video';
  public function render() {
    $this->html_params['src'] = $this->getTarget();
    $this->html_params['preload'] = 'auto';
    $this->html_params['controls'] = TRUE;
    $this->html_body = 'Your browser does not support the video element.';
    return parent::render();
  }
}
