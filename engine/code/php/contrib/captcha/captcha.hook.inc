<?php
/**
 * Implements hook_init().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function captcha_init($env, $vars) {
  $env->addInclude('engine/code/php/contrib/captcha/css/captcha.css');
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
  $captcha = new Captcha($env, CAPTCHA_MATH);
  $vars['shadow']->prependToTab(USER_ACTION_REGISTER, $captcha->render());
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
