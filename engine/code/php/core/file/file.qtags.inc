<?php
/**
 * Implements FILEATTRIBUTE qtag.
 *
 * Renders an attribute of a file.
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
 *
 * Render a file item.
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
function qtag_FILE($env, $target, $attributes) {
  $file = new File($env, $target, NULL, $attributes['title']);
  // Return the rendered file, if exists.
  if ($file->exists) {
    return $file->render();
  }
  else {
    return NULL;
  }
}


/**
 * Implements PREVIEW qtag.
 *
 * Render a preview of a file.
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
function qtag_PREVIEW($env, $target, $attributes) {

  // TODO: implement.
  // return 'PREVIEW';

}
