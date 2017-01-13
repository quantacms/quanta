<?php
function wiki_FILEATTRIBUTE($attributes, $file, $string, $env) {

  $node = NodeFactory::current($env);
  $file = new File($env, $file, $node);


  switch($attributes['name']) {
    case 'name':
      $string = $file->getName();
      break;

    case 'path':
      $string = $file->getPath();

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
 */
function wiki_FILE($attributes, $file, $string, $env) {
  $node =  NodeFactory::current($env);
  $file = new File($env, $file, $node);
  //$file->loadAttributes($attributes);
  return $file->render();
}