<?php
/**
 * Implements hook_doctor_setup().
 *
 * Setup a Quanta site.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function doctor_doctor_setup($env, $vars) {
  /** @var Doctor $doctor */
  $doctor = $vars['doctor'];
  $doctor->op('Starting Quanta installation. Good luck!');

  // Check that all system paths do exist.
  $doctor->checkCurrentUnixUser();

  // Check that all system paths do exist.
  $doctor->checkApacheModules();

  // Check that all system paths do exist.
  $doctor->checkApacheUser();

  $doctor->ok('Current user: ' . $doctor->unix_user);
  $doctor->ok('Apache user: ' . $doctor->apache_user);

  $user_is_correct = $doctor->ask('Are those users correct? (y/n)');
  if ($user_is_correct == 'n') {
    $doctor->stop('Aborting.');
  }

  // Check that all system paths do exist.
  $doctor->checkSystemPaths();

  // Clear all cache (for existing applications).
  $doctor->cure('clear_cache');

  $doctor->ok();

  // TODO: move in modules - out of doctor.
  $doctor->checkExistingIndex();
  $doctor->checkExistingHome();
  $doctor->checkAdminUser();
  $doctor->cure('check');

  $doctor->checkApacheVsUnixUser();
}

/**
 * Implements hook_doctor_setup().
 *
 * Setup a Quanta site.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function doctor_doctor($env, $vars) {
  /** @var Doctor $doctor */
  $doctor = $vars['doctor'];
  // TODO: move in modules - out of doctor.
  $doctor->checkBrokenLinks();
}
