<?php
function wiki_FILEATTRIBUTE($attributes, $file, $string, $env) {

  $node = empty($attributes['node']) ? NodeFactory::current($env) : NodeFactory::load($env, $attributes['node']);
  $file = new File($env, $file, $node);

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
 * Wiki Hook for FILE.
 * @param $attributes
 * @param $file
 * @param $string
 * @param $env
 * @return null|string
 */
function wiki_FILE($attributes, $file, $string, $env) {

  $file = new File($env, $file, NULL, $attributes['title']);
  if ($file->exists) {
    return $file->render();
  }
  //$file->loadAttributes($attributes);
}