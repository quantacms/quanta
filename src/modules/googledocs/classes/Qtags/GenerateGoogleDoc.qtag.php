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
    $generate_path = 'generate-google-doc';
    $node_name = $this->getTarget();
    $key =  $this->getAttribute('key');
    $query = $this->getAttribute('query');
    $file_title = $this->getAttribute('file-title');
    $path = $this->env->request_path;

    $this->setTarget("{$generate_path}?{$query}&path={$path}&file-title={$file_title}&node_name={$node_name}&key={$key}");
    return parent::render();
}

}
