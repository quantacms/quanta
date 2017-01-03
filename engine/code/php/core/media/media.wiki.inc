<?php

/**
 * Detect content PLAYLIST and eventually create HTML.
 */
function wiki_PLAYLIST($attributes, $value, $string, $env) {
  $dirlist = new PlayList($env, $value, 'playlist', array('sortbytime' => 'desc') + $attributes);
  return $dirlist->render();
}

function wiki_AUDIO($attributes, $value, $string, $env) {

  return '<audio src="' . $value . '" preload="auto" controls>
  <p>Your browser does not support the audio element.</p>
</audio>';
}

function wiki_ALBUM($attributes, $value, $string, $env) {
  $dirlist = new Album($env, $value, 'album', array('sortbytime' => 'desc') + $attributes);
  return $dirlist->render();
}