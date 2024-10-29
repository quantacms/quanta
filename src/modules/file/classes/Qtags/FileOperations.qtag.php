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
    $nodeobj = \Quanta\Common\NodeFactory::loadOrCurrent($this->env, $node_name);
    $img_name = $this->getAttribute('img');
    $img_key = $this->getAttribute('key');
    $show_buttons = !empty($this->getAttribute('show_buttons')) ? $this->getAttribute('show_buttons') : 'delete-btn,set-as-thumbnail-btn';
    $user = \Quanta\Common\UserFactory::Current($this->env);
    $is_anonymous = in_array('anonymous',$user->roles);
    if (!$is_anonymous && \Quanta\Common\NodeAccess::check($this->env, \Quanta\Common\Node::NODE_ACTION_EDIT, array('node' => $nodeobj))) {
      $this->addClass('file-operation');
      $this->attributes['attr-data-img_node'] = $node_name;
      $this->attributes['attr-data-img'] = $img_name;
      $this->attributes['attr-data-img_key'] = $img_key;
      $this->attributes['attr-data-show-buttons'] = $show_buttons;
    }
    return parent::render();
  }
}
