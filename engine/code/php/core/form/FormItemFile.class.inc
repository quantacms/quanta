<?php
/**
 * Class FormItemFile
 * This class represents a Form Item of type File upload
 */
class FormItemFile extends FormItem {
  public $type = 'file';


  /**
   * Renders the input item.
   * @return mixed
   */
  function render() {
    switch($this->getData('plugin')) {
      case 'drop':
      default:
        $rendered = '<div id="drop">Drop here files<a>or press here</a><input type="file" name="upl" id="' . $this->getData('id') . '" multiple /></div>';
        break;
    }
    return $rendered;
  }

  function loadAttributes() {
    $this->setData('plugin', !empty($this->getInputAttr('plugin') ? $this->getInputAttr('plugin') : 'drop'));
  }
}
