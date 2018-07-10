<?php
/**
 * Implements DOCTOR_TIMESTAMP qtag.
 *
 * Returns the timestamp of the last time doctor was ran.
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
function qtag_DOCTOR_TIMESTAMP($env, $target, $attributes) {
	return Doctor::timestamp($env);
}
