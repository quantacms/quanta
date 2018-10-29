<?php

/**
 * Implements INPUT qtag.
 *
 * Renders an input item of a form.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_INPUT($env, $target, &$attributes) {
  $form = FormFactory::getForm($env, $target);
  FormFactory::createInputItem($env, $attributes, $form);
  $rendered = str_replace('[INPUT|', '[INPUT_RENDER|', $attributes['tag_full']);
  unset($attributes['grid']);
  return $rendered;
}

/**
 * Implements INPUT_RENDER qtag.
 *
 * Helper qtag, that renders an INPUT after all input items are loaded.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_INPUT_RENDER($env, $target, &$attributes) {

  $rendered = '';
  $form = FormFactory::getForm($env, $target);
  if (!(empty($attributes['name'])) && !(empty($form->getItem($attributes['name'])))) {

    $input = $form->getItem($attributes['name']);
    if ($input->isFirst()) {
      $attributes['prefix'] = $form->renderFormOpen();
    }

    $rendered .= ($form->isValidated()) ? '' : $input->renderFormItem();

    if ($input->isLast()) {
        $attributes['suffix'] = $form->renderFormClose();
    }
  }

  return $rendered;
}

/**
 * Implements FORM qtag.
 *
 * Renders a form.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_FORM($env, $target, &$attributes) {
  $form = FormFactory::getForm($env, $target);
  $form->loadAttributes($attributes);
  $string = '';
  // If the form has been submitted, validate it.
  if ($form->isSubmitted() && ($validate_ok = $form->checkValidate())) {
    $string = $validate_ok;
  }
  return $string;
}

/**
 * Implements LIST_VALUES qtag.
 *
 * Use subnodes of a node as possible values for a form item.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_LIST_VALUES($env, $target, &$attributes) {
  $attributes['editable'] = 'false';
  $attributes['clean'] = TRUE;
  $attributes['separator'] = ',';
  $dirlist = new DirList($env, $target, 'list-values', $attributes, 'form');
	return $dirlist->render();
}

/**
 * Implements LIST_OPTIONS qtag.
 *
 * Use subnodes of a node as possible options for a select item.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_LIST_OPTIONS($env, $target, &$attributes) {
  $attributes['editable'] = 'false';
  $attributes['clean'] = TRUE;
  $attributes['separator'] = '';
  $dirlist = new DirList($env, $target, 'list-options', $attributes, 'form');
  return $dirlist->render();
}
