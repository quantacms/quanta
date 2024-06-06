<?php
namespace Quanta\Qtags;

/**
 * Class FormItemFile
 * This class represents a Form Item of type File upload
 */
class FormItemFile extends FormItemString {
  public $type = 'file';

  /**
   * Renders the file input item.
   * @return mixed
   */
  function render() {
    $isMultiple = $this->getAttribute('single') ? "" : "multiple";
    $setAsThumbnail = $this->getAttribute('single') && !$this->getAttribute('not-thumbnail') ? "thumbnail=true" : "thumbnail=false";
    if(!$setAsThumbnail){
      $this->html_params['not-thumbnail']= 'not-thumbnail';
    }
    $this->html_params['single'] = $this->getAttribute('single');
    switch($this->getAttribute('plugin')) {
      case 'drop':
      default:
        $rendered = '<input type="hidden" name="tmp_upload_dir" value="[ATTRIBUTE|name=tmp_files_dir]" />' . '<div class="upload-files"><div class="drop">Drop here files<a>or press here</a><input type="file" name="' . $this->getName() . '" id="' . $this->getId() . '" ' .$isMultiple .' ' .$setAsThumbnail .' /></div></div>';
        break;
      case 'non-drop':
        $rendered = '<input type="hidden" name="tmp_upload_dir" value="[ATTRIBUTE|name=tmp_files_dir]" />' . '<div class="upload-files"><div class="drop"><a>Press here</a><input type="file" name="' . $this->getName() . '" id="' . $this->getId() . '" ' .$isMultiple .' ' .$setAsThumbnail .'  /></div></div>';
        break;
      case 'csv-type':
        $rendered = '<input type="hidden" name="tmp_upload_dir" value="[ATTRIBUTE|name=tmp_files_dir]" />' . '<div class="upload-files"><div class="drop">Drop here files<a>or press here</a><input type="file" name="' . $this->getName() . '" id="' . $this->getId() . '" ' .$isMultiple .' ' .$setAsThumbnail .' accept=".csv"/></div></div>';
        break;

    }
    return $rendered;
  }

  function loadAttributes() {
    $this->setData('plugin', !empty($this->getAttribute('plugin') ? $this->getAttribute('plugin') : 'drop'));
  }
}
