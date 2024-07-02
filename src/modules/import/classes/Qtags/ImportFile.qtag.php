<?php
namespace Quanta\Qtags;

/**
 * Class ImportFile
 * 
 */
class ImportFile extends Edit {

  /**
   * Renders.
   * @return mixed
   */
  function render() {
    $this->attributes['widget'] = 'single';
    $this->attributes['components'] = 'node_form,import_file_form';
    return parent::render();
  }

}
