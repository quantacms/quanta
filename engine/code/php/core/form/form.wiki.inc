<?php
/**
 * Renders a form input.
 *
 * @param $attributes string
 * @param $form_id
 * @param $string string
 * @param $env
 * @return mixed
 * @internal param string $replace
 * @internal param string $tagname
 * @internal param Page $page
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
  if (empty($attributes['name']) || empty($form->getItem($attributes['name']))) {
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
 * @param $attributes string
 * @param $form_id
 * @param $string string
 * @param $env
 * @return mixed
 * @internal param string $replace
 * @internal param string $tagname
 */
function wiki_FORM($attributes, $form_id, $string, $env) {
  $form = FormFactory::getForm($env, $form_id);
  $form->loadAttributes($attributes);

  if ($form->isSubmitted() && ($validate_ok = $form->checkValidate())) {
    return $validate_ok;
  }
}

/**
 * Use subnodes of a node as possible values for a form item.
 * @param $attributes
 * @param $node
 * @param $string
 * @param $env
 * @return bool|mixed|string
 */
function wiki_LIST_VALUES($attributes, $node, $string, $env) {
  $attributes['nowrap'] = TRUE;
  $attributes['clean'] = TRUE;
  $attributes['separator'] = ',';
  $dirlist = new DirList($env, $node, 'list-values', $attributes, 'form');

  return $dirlist->render();
}