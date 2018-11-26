<?php
namespace Quanta\Common;

/**
 * Implements qtag PLAYLIST.
 *
 * Create a list of HTML5 playable audio files.
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
function qtag_PLAYLIST($env, $target, &$attributes) {
  $dirlist = new PlayList($env, $target, 'playlist', array('sortbytime' => 'desc') + $attributes);
  return $dirlist->render();
}

/**
 * Implements qtag AUDIO.
 *
 * Render a playable audio file.
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
function qtag_AUDIO($env, $target, &$attributes) {
  return '<audio src="' . $target . '" preload="auto" controls><p>Your browser does not support the audio element.</p></audio>';
}

/**
 * Implements qtag ALBUM.
 *
 * Render an Album of audio files.
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
function qtag_ALBUM($env, $target, &$attributes) {
  $dirlist = new Album($env, $target, 'album', array('sortbytime' => 'desc') + $attributes);
  return $dirlist->render();
}
