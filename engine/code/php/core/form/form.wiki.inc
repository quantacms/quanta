<?php
/**
 * @param $replace string
 * @param $tagname string
 * @param $attributes string
 * @param $string string
 * @param $page Page
 * @return mixed
 */
function wiki_INPUT($attributes, $form_id, $string, $env) {
  $form = $env->getData('form_' . $form_id, array());
  $form[] = $attributes;
  $env->setData('form_' . $form_id, $form);
}

/**
 * @param $replace string
 * @param $tagname string
 * @param $attributes string
 * @param $string string
 * @return mixed
 */
function wiki_FORM($attributes, $form_id, $string, $env) {

  // FORM submission handler.
  if (isset($_REQUEST['form_submit'])) {
    $validate_ok = form_form_load($env, $_REQUEST['form']);
    if ($validate_ok) {
      return (isset($attributes['ok_message']) ? $attributes['ok_message'] : 'Your form has been submitted.');
    }
  }

  $form = $env->getData('form_' . $form_id);
  $form[] = array(
    'name' => 'form',
    'value' => $form_id,
    'type' => 'hidden',
    );

  foreach ($form as $k => $attributes) {
    $defaults = array(
      'length' => 30,
      'cols' => 100,
      'rows' => 10,
      'type' => isset($attributes['values']) ? 'select' : 'string',
      'title' => str_replace('-', ' ', $attributes['name']) . ':',
    );

    foreach ($defaults as $k => $v) {
      if (!isset($attributes[$k])) {
        $attributes[$k] = $v;
      }
    }

    $classes_item = array('form-item', 'form-item-' . $attributes['name'], 'form-type-' . $attributes['type']);
    $classes_element = array('form-element');

    $attributes['label'] = ($attributes['type'] != 'submit') ? $attributes['title'] : '';
    if (!empty($attributes['required'])) {
      $classes_element[] = 'required';
      $classes_item[] = 'required';
    }

    if (isset($_SESSION['validation_errors'][$_REQUEST['form']][$attributes['name']])) {
      $classes_item[] = 'validation-error';
    }
    $classes_element_str = implode(' ', $classes_element);
    $classes_item_str = implode(' ', $classes_item);
    $default = (isset($_REQUEST[$attributes['name']])) ? $_REQUEST[$attributes['name']] : NULL;

    if (isset($attributes['value'])) {
      $default = $attributes['value'];
    }

    $id = isset($attributes['id']) ? $attributes['id'] : 'input-' . $attributes['name'];
    switch ($attributes['type']) {
      case 'select':
        $input = explode(',', $attributes['values']);
        $obj = '<select name="' . $attributes['name'] . '" class="' . $classes_element_str . '">';
        foreach ($input as $v) {
          $v = trim($v);
          $obj .= '<option value="' . $v . '" ' . (($v == $default) ? 'selected' : '') . '>' . trim($v) . '</option>';
        }
        $obj .= '</select>';
        break;

      case 'hidden':
        $obj = '<input id="' . $id . '" type="hidden" name="' . $attributes['name'] . '" length="' . $attributes['length'] . '" value="' . $attributes['value'] . '"  class="' . $classes_element_str . '"/>';
        break;
      case 'string':
        $obj = '<input id="' . $id . '" value="' . $default . '" type="text" name="' . $attributes['name'] . '" length="' . $attributes['length'] . '"  class="' . $classes_element_str . '"/>';
        break;
      case 'password':
        $obj = '<input id="' . $id . '" value="' . $default . '" type="password" name="' . $attributes['name'] . '" length="' . $attributes['length'] . '"  class="' . $classes_element_str . '"/>';
        break;
      case 'checkbox':
        $obj = '<input id="' . $id . '"  value="' . $default . '" type="checkbox" name="' . $attributes['name'] . '" class="' . $classes_element_str . '"/>' . $attributes['label'];
        break;
      case 'date':
        $obj = '<input id="' . $id . '"  value="' . $default . '" type="text" name="' . $attributes['name'] . '" class="hasDatepicker ' . $classes_element_str . '"/>';
        break;
      case 'time':
        $obj = '<input id="' . $id . '"  value="' . $default . '" type="text" name="' . $attributes['name'] . '" class="' . $classes_element_str . '"/>';
        break;

      case 'submit':
        $obj = '<input id="' . $id . '"  type="submit" name="' . $attributes['submit'] . '" value="' . $attributes['title'] . '"  class="' . $classes_element_str . '"/>';
        break;

      case 'text':
        $obj = '<textarea id="' . $id . '"  name="' . $attributes['name'] . '" cols="' . $attributes['cols'] . '"  rows="' . $attributes['rows'] . '"  class="' . $classes_element_str . '">' . $default . '</textarea>';
        break;

      case 'file':
        if ($attributes['plugin'] == 'drop') {
          $obj = '<div id="drop">Drop here files<a>or press here</a><input type="file" name="upl" id="' . $id. '" multiple /></div>';
        }
        break;
      default:
        print "Unknown type:" . $attributes['type'];
        break;
    }

    $label = ((trim($attributes['label']) != '') && ($attributes['type'] != 'hidden')) ? ('<div class="form-item-label"><label>' . $attributes['label'] . '</label></div>') : '';
    $form_items[] = '<div class="' . $classes_item_str . '">' . $label . $obj . '</div>';
  }

  $html = '<form method="post" id="' . $form_id . '"><input type="hidden" name="form_submit"/> ' . implode('', $form_items) . '</form>';
  return $html;
}
