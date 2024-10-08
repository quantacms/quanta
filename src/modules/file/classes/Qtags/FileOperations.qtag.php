<?php
namespace Quanta\Qtags;

/**
 * Renders a file operations.
 */
class FileOperations extends HtmlTag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node_name = $this->getAttribute('img_node');
    $img_name = $this->getAttribute('img');
    $img_key = $this->getAttribute('key');
    $user = \Quanta\Common\UserFactory::Current($this->env);
    $is_anonymous = in_array('anonymous',$user->roles);
    if(!$is_anonymous){
      $this->addClass('file-operation');
      $this->attributes['attr-data-img_node'] = $node_name;
      $this->attributes['attr-data-img'] = $img_name;
      $this->attributes['attr-data-img_key'] = $img_key;
    }
    return parent::render();
  }
}
