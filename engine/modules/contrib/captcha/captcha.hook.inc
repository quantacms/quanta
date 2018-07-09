<?php
/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function captcha_load_includes($env, $vars) {
  $module_path = $env->getModulePath('captcha');
  $env->addInclude($module_path . '/css/captcha.css');  
}

/**
 * Implements hook_user_register().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function captcha_shadow_user_register($env, $vars) {
  /** @var Shadow $shadow */
  $shadow = $vars['shadow'];
  $captcha = new Captcha($env, CAPTCHA_MATH);
  $shadow->prependToTab(USER_ACTION_REGISTER, $captcha->render());
}

/**
 * Implements hook_user_validate().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function captcha_user_validate($env, $vars) {
  if ($vars['user']->context == USER_ACTION_REGISTER) {
    if (!Captcha::validate($vars['form_data']['edit-captcha'], $vars['form_data']['edit-captcha_math'])) {
      $env->setData(USER_VALIDATION_ERROR, TRUE);
      new Message($env, 'The math answer is not valid. Please retry', MESSAGE_WARNING);
    }
  }
}
