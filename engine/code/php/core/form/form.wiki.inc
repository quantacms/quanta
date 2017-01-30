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
  $form = FormFactory::getForm($env, $form_id);
  $form->addItem($attributes['name'], FormFactory::createInputItem($env, $attributes));
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
  $form = FormFactory::getForm($env, $form_id);
  $form->loadAttributes($attributes);
  $form->addItem('form', FormFactory::createInputItem($env, array('type' => 'hidden', 'name' => 'form', 'value' => $form_id)));
  if ($validate_ok = $form->checkValidate()) {
    return $validate_ok;
  }

  return $form->render();
}
