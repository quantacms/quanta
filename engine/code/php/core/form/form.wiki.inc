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
  $rendered = '';

  $form = FormFactory::getForm($env, $form_id);
  FormFactory::createInputItem($env, $attributes, $form);
  $rendered .= str_replace('[INPUT|', '[INPUT_RENDER|nowiki|', $attributes['tag_full']);
  return $rendered;
}

function wiki_INPUT_RENDER($attributes, $form_id, $string, $env) {

  $rendered = '';
  $form = FormFactory::getForm($env, $form_id);
  if (empty($form->getItem($attributes['name']))) {
    return;
  }
  $input = $form->getItem($attributes['name']);
  if ($input->isFirst()) {
    $rendered .= $form->renderFormOpen();
  }

  $rendered .= ($form->isValidated()) ? '' : $input->renderFormItem();

  if ($input->isLast()) {
      $rendered .= $form->renderFormClose();
  }
  return $rendered;
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

  if ($form->isSubmitted() && ($validate_ok = $form->checkValidate())) {
    return $validate_ok;
  }
}
