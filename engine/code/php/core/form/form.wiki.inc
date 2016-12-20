<?php
/**
 * Renders a form input.
 *
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
 * Renders a form.
 *
 * @param $replace string
 * @param $tagname string
 * @param $attributes string
 * @param $string string
 * @return mixed
 */
function wiki_FORM($attributes, $form_id, $string, $env) {

	// Determine in which page the form loads.
	$action = (isset($attributes['action'])) ? $attributes['action'] : '';
	$method = (isset($attributes['method'])) ? $attributes['method'] : 'post';

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
    $tag_attributes_arr = array();

    $defaults = array(
      'type' => isset($attributes['values']) ? 'select' : 'string',
      'title' => str_replace('-', ' ', $attributes['name']) . ':',
    );

    foreach ($defaults as $k => $v) {
      if (!isset($attributes[$k])) {
        $attributes[$k] = $v;
      }
    }

    if ($attributes['type'] == 'date' || $attributes['type'] == 'time' || $attributes['type'] == 'string' || $attributes['type'] == 'number') {
      $type = 'text';
      $tag_attributes_arr[] = 'type="text"';
    } else {
      $type = $attributes['type'];
    }

    $tag_attributes_arr[] = 'title="' . $attributes['title'] . '"';
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

    $default = (isset($_REQUEST[$attributes['name']])) ? $_REQUEST[$attributes['name']] : NULL;

    if (isset($attributes['value'])) {
      $default = $attributes['value'];
    }

    $tag_attributes_arr[] = 'name="' . $attributes['name'] . '"';

    $id = isset($attributes['id']) ? $attributes['id'] : 'input-' . $attributes['name'];
    $tag_attributes_arr[] = 'id="' . $id . '"';

    if (isset($attributes['disabled'])) {
      $tag_attributes_arr[] = 'disabled';
      $classes_element[] = 'form-item-disabled';
    }
    if (isset($attributes['cols'])) {
      $tag_attributes_arr[] = 'cols="' . $attributes['cols'] . '"';
    }
    if (isset($attributes['rows'])) {
      $tag_attributes_arr[] = 'rows="' . $attributes['rows'] . '"';
    }

    $classes_element_str = implode(' ', $classes_element);
    $classes_item_str = implode(' ', $classes_item);
    $tag_attributes_arr[] = 'class="' . $classes_element_str . '"';


    $tag_attributes = implode(' ', $tag_attributes_arr);

    switch ($attributes['type']) {
      case 'select':
        $values = array();
        if (isset($attributes['range'])) {
          $split = explode('-', $attributes['range']);
          for ($i = $split[0]; $i < $split[1]; $i++) {
            $values[$i] = $i;
          }
        }
        elseif (isset($attributes['values'])) {
          $values = explode(',', $attributes['values']);
        }
        $obj = '<select ' . $tag_attributes . '>';
        foreach ($values as $v) {
          $v = trim($v);
          $obj .= '<option value="' . $v . '" ' . (($v == $default) ? 'selected' : '') . '>' . trim($v) . '</option>';
        }
        $obj .= '</select>';
        break;

      case 'hidden':
      case 'string':
      case 'number':
      case 'password':
      case 'submit':
        $obj = '<input value = "' . $default . '" type = "' . $type . '" ' . $tag_attributes . '/>';
        break;

      case 'checkbox':
        $obj = '<input id="' . $id . '"  value="' . $default . '" type="checkbox" name="' . $attributes['name'] . '" class="' . $classes_element_str . '"/>' . $attributes['label'];
        break;

      case 'date':
        $obj = '<input class="hasDatepicker ' . $tag_attributes . '"/>';
        break;

      case 'time':
        $obj = '<input id="' . $id . '"  value="' . $default . '" type="text" name="' . $attributes['name'] . '" class="' . $classes_element_str . '"/>';
        break;

      case 'text':
        $obj = '<textarea ' . $tag_attributes . 'cols="' . $attributes['cols'] . '"  rows="' . $attributes['rows'] . '"  class="' . $classes_element_str . '">' . $default . '</textarea>';
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

  $html = '<form action="/' . $action . '" method="' . $method . '" id="' . $form_id . '"><input type="hidden" name="form_submit"/> ' . implode('', $form_items) . '</form>';
  return $html;
}
