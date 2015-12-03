<?php
/**
 * @param $replace string
 * @param $tagname string
 * @param $attributes string
 * @param $string string
 * @param $page Page
 * @return mixed
 */
function wiki_INPUT($replace, $attributes, $form_id, $string, $page) {
  $form = $page->getData('form_' . $form_id, array());
  $form[] = $attributes;
  $page->setData('form_' . $form_id, $form);
  return str_replace($replace, '', $string);
}

/**
 * @param $replace string
 * @param $tagname string
 * @param $attributes string
 * @param $string string
 * @param $page Page
 * @return mixed
 */
function wiki_FORM($replace, $attributes, $form_id, $string, $page) {
  $form = $page->getData('form_' . $form_id);

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
  $attributes['label'] = ($attributes['type'] != 'submit') ? $attributes['title'] : '';


  switch ($attributes['type']) {
    case 'select':
      $input = explode(',', $attributes['values']);
      $obj = '<select name="' . $attributes['name'] . '">';
      foreach ($input as $v) {
        $obj .= '<option value="' . trim($v) . '">' . trim($v) . '</option>';
      }
      $obj .= '</select>';
      break;

    case 'hidden':
      $obj = '<input type="hidden" name="' . $attributes['name'] . '" length="' . $attributes['length'] . '" value="' . $attributes['value'] . '"/>';
      break;
    case 'string':
      $obj = '<input type="text" name="' . $attributes['name'] . '" length="' . $attributes['length'] . '" />';
      break;

    case 'submit':
      $obj = '<input type="submit" name="' . $attributes['submit'] . '" value="' . $attributes['title'] . '" />';
      break;

    case 'text':
      $obj = '<textarea name="' . $attributes['name'] . '" cols="' . $attributes['cols'] . '"  rows="' . $attributes['rows'] . '"></textarea>';
      break;

    default:
      print "Unknown type:" . $attributes['type'];
      break;
  }

    $label = ($attributes['type'] != 'hidden') ? ('<div class="form-item-label"><label>' . $attributes['label'] . '</label></div>') : '';
    $form_items[] = '<div>' . $label . $obj . '</div>';
}

  $html = '<form method="post">' . implode('', $form_items) . '</form>';
  return str_replace($replace, $html, $string);
}
