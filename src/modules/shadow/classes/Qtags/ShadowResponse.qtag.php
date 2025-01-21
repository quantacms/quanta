<?php
namespace Quanta\Qtags;
/**
 *
 */
class ShadowResponse extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $html = "[INPUT|class=hidden|type=hidden|id=edit_shadow|name=shadow_response|value=true:business-form]";
    $basic_attributes = array(
      'context',
      'module',
      'widget',
      'components',
      'language',
      'node',
      'child_node',
      'redirect',
      'entity'
    );
    $extra_attributes = array();
    foreach ($this->attributes as $key => $value) {
      if(in_array($key, $basic_attributes)){
        $html .= "[INPUT|class=hidden|type=hidden|id=edit_{$key}|name=shadow_{$key}|value={$value}:business-form]";
      }
      else{
        $html .= "[INPUT|class=hidden|type=hidden|id=edit_extra_attribute_key_{$key}|name=shadow_extra_attributes_keys|value={$key}:business-form]";
        $html .= "[INPUT|class=hidden|type=hidden|id=edit_extra_attribute_value_{$key}|name=shadow_extra_attributes_values|value={$value}:business-form]";
      }
    }
    return $html;
  }
}
