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
    $read_path = 'read-google-doc';
    $doc_id = $this->getAttribute('doc-id');
    $query = $this->getAttribute('query');
    $path = $this->env->request_path;
    $this->setTarget("{$read_path}?doc-id={$doc_id}&{$query}&path={$path}");
    return parent::render();
}

}
