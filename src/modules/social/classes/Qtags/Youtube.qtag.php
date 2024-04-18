<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;

/**
 * Create social sharing buttons for a node.
 */
class Youtube extends HtmlTag {
  protected $html_tag = 'iframe';

  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $this->html_params['width'] = $this->getAttribute('width', 560);
    $this->html_params['height'] = $this->getAttribute('height', 315);
    $this->html_params['src'] = 'https://www.youtube.com/embed/' . $this->getTarget();
    $this->html_params['allow'] = 'accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture';
    $this->html_params['allowfullscreen'] = TRUE;
    return parent::render();
  }
}
