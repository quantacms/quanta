<?php



/**
 * Implements hook_cron().
 * @param $vars
 */
function wiki_MAILFORM($attributes, $form_id, $string, $page) {
  $form_id = 'mailform';
  $inputs = array_flip(array('to', 'from', 'from_name', 'to_name'));

  if (isset($_REQUEST['to'])) {
    return '<b>Your email has been sent. Thank you!</b>';
  }
  else {
    // Add mail attributes
    foreach ($attributes as $k => $val) {
      if (isset($inputs[$k])) {
        wiki_INPUT(array('type' => 'hidden', 'name' => $k, 'value'=> $attributes[$k]), $form_id, $string, $page);
      }
    }
    $form = wiki_FORM($attributes, $form_id, $string, $page);
    return $form;
  }
}