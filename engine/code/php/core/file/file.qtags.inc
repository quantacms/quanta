<?php
/**
 * Implements FILEATTRIBUTE qtag.
 */
function qtag_FILEATTRIBUTE($env, $target, $attributes) {
  $node = empty($attributes['node']) ? NodeFactory::current($env) : NodeFactory::load($env, $attributes['node']);
  $file = new File($env, $target, $node);
  $string = NULL;

  // Check which file attribute is requested, and provide it.
  switch($attributes['name']) {

    case 'name':
      $string = $file->getName();
      break;

    case 'path':
      $string = $file->getFullPath();
      break;

    case 'type':
      $string = $file::getFileType($file->getExtension());
      break;

    case 'size':
      $string = $file->getFileSize();
      break;
  }

  return $string;
}

/**
 * Implements FILE qtag.
 */
function qtag_FILE($env, $target, $attributes) {

  $file = new File($env, $target, NULL, $attributes['title']);
  // Return the rendered file.
  if ($file->exists) {
    return $file->render();
  }
  else {
    return NULL;
  }
}