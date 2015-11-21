<?php

function captcha_init($vars) {
  $vars['env']->addInclude('engine/code/php/contrib/captcha/css/captcha.css');
}

function captcha_shadow_user_register($vars) {
  $captcha = new Captcha($vars['env'], CAPTCHA_MATH);
  $vars['shadow']->prependToTab(USER_ACTION_REGISTER, $captcha->render());
}

function captcha_user_validate($vars) {
  if ($vars['user']->context == USER_ACTION_REGISTER) {
    if (!Captcha::validate($vars['form_data']['edit-captcha'], $vars['form_data']['edit-captcha_math'])) {
      $vars['env']->setData(USER_VALIDATION_ERROR, TRUE, 'captcha');
      new Message($vars['env'], 'The math answer is not valid. Please retry', MESSAGE_WARNING);
    }
  }
}