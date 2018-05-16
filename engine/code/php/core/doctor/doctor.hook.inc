<?php
/**
 * Implements hook_boot().
 *
 * Creates a temporary dir for doctor.
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
/**
 * Implements hook_boot().
 *
 * React to robots.txt requests.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function doctor_boot($env, $vars) {
  $env->tmpdir('doctor', 'doctor');
}

/**
 * Implements hook_doctor().
 * Add quanta's basic includes (JS / CSS files)
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
/**
 * Implements hook_boot().
 *
 * React to robots.txt requests.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function doctor_doctor($env, $vars) {
  $env->tmpdir('doctor', 'doctor');
  touch($env->dir['doctor'] . '/doctor.txt');
}
