<?php
/**
 * Class FormItemFile
 * This class represents a Form Item of type File upload
 */
class FormItemFile extends FormItem {
  public $type = 'file';


  /**
   * Renders the file input item.
   * @return mixed
   */
  function render() {
    switch($this->getData('plugin')) {
      case 'drop':
      default:
        $rendered = '<input type="hidden" name="tmp_upload_dir" value="[ATTRIBUTE|name=tmp_files_dir]" />' . '<div class="upload-files"><div class="drop">Drop here files<a>or press here</a><input type="file" name="' . $this->getName() . '" id="' . $this->getData('id') . '" multiple /></div></div>';
        break;
    }
    return $rendered;
  }

  function loadAttributes() {
    $this->setData('plugin', !empty($this->getInputAttr('plugin') ? $this->getInputAttr('plugin') : 'drop'));
  }
}
