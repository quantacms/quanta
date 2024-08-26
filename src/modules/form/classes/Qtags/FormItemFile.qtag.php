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
    $setAsThumbnail = !$this->getAttribute('not-thumbnail') ? "thumbnail=true" : "thumbnail=false";
    if(!$setAsThumbnail){
      $this->html_params['not-thumbnail']= 'not-thumbnail';
    }
    $this->html_params['single'] = $this->getAttribute('single');

    $rendered_icon = '<img class="upload-icon" src="/modules/file/assets/img/icons/upload-icon.png">';
    $rendered_input = '<input type="file" name="' . $this->getName() . '" id="' . $this->getId() . '" ' .$isMultiple .' ' .$setAsThumbnail;
    $rendered_drop = '[TEXT|tag=drop-here-files:Drop here files]<a>[TEXT|tag=or-press-here:or press here]</a>';
    $rendered = '<input type="hidden" name="tmp_upload_dir" value="[ATTRIBUTE|name=tmp_files_dir]" />' . '<div class="upload-files"><div class="drop">';
    
    switch($this->getAttribute('plugin')) {
      case 'drop':
      default:
        $rendered .= $rendered_icon . $rendered_drop . $rendered_input . ' /></div></div>';
        break;
      case 'non-drop':
        $rendered_drop = '<a>[TEXT|tag=press-here-to-upload:Press here to upload]</a>';
        $rendered .= $rendered_icon . $rendered_drop . $rendered_input . ' /></div></div>';
        break;
      case 'csv-type':
        $rendered .= $rendered_icon . $rendered_drop . $rendered_input . ' accept=".csv"/></div></div>';
        break;

    }
   
    return $rendered;
  }

  function loadAttributes() {
    $this->setData('plugin', !empty($this->getAttribute('plugin') ? $this->getAttribute('plugin') : 'drop'));
  }
  public function validate() {
    return TRUE;
  }
}
