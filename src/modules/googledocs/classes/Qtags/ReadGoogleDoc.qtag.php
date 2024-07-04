<?php
namespace Quanta\Qtags;

/**
 * 
 */
class ReadGoogleDoc extends Link {

  /**
   * Renders.
   * @return mixed
   */
  function render() {
    $read_path = \Quanta\Common\GoogleDocs::READ_GOOGLE_DOC_PATH;
    $doc_id = $this->getAttribute('doc-id');
    $query = $this->getAttribute('query');
    $this->setTarget("{$read_path}?doc-id={$doc_id}&{$query}");
    return parent::render();
}

}
