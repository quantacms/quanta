<?php
namespace Quanta\Qtags;

/**
 * 
 */
class GenerateGoogleDoc extends Link {

  /**
   * Renders.
   * @return mixed
   */
  function render() {
    $generate_path = \Quanta\Common\GoogleDocs::GENERATE_GOOGLE_DOC_PATH;
    $text = $this->getTarget();
    $query = $this->getAttribute('query');
    $this->setTarget("{$generate_path}?text={$text}&{$query}");
    return parent::render();
}

}
