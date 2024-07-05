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
    $file_title = $this->getAttribute('file-title');
    $path = $this->env->request_path;
    $this->setTarget("{$generate_path}?{$query}&path={$path}&file-title={$file_title}&text={$text}");
    return parent::render();
}

}
