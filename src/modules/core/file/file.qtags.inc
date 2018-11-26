<?php
namespace Quanta\Common;

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
function qtag_FILEATTRIBUTE($env, $target, &$attributes) {
  $node = empty($attributes['node']) ? NodeFactory::current($env) : NodeFactory::load($env, $attributes['node']);
  $file = new FileObject($env, $target, $node);
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
function qtag_FILE($env, $target, &$attributes) {
  $node = empty($attributes['node']) ? NodeFactory::current($env) : NodeFactory::load($env, $attributes['node']);

  $file = new FileObject($env, $target, $node, $attributes['title']);
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
function qtag_FILE_PREVIEW($env, $target, &$attributes) {
  $node = empty($attributes['node']) ? NodeFactory::current($env) : NodeFactory::load($env, $attributes['node']);
  if (isset($attributes['tmp_path'])) {
    $target = $env->dir['tmp'] . '/files/' . $attributes['tmp_path'] . '/' . $target;
    $node->setName(NODE_NEW);
  }

  $file = new FileObject($env, $target, NODE_NEW);
  $preview = '';
  switch($file->getType()) {
    case 'image':
      $attributes['150x150'] = TRUE;
      $attributes['node'] = $node->getName();
      $preview = qtag_IMGTHUMB($env, $target, $attributes);
      break;

    default:
      break;
  }

  return '<div class="file-preview-item file-' . $file->getType() . '">' . $preview . '</div>';
}

/**
 * Implements FILE_QTAG_SUGGESTION qtag.
 *
 * Provides a "suggested qtag" for rendering a file.
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
function qtag_FILE_QTAG_SUGGESTION($env, $target, &$attributes) {
  $node = empty($attributes['node']) ? NodeFactory::current($env) : NodeFactory::load($env, $attributes['node']);
  if (isset($attributes['tmp_path'])) {
    $file = new FileObject($env, $env->dir['tmp'] . '/files/' . $attributes['tmp_path'] . '/' . $target, NODE_NEW);
  }
  else {
    $file = new FileObject($env, $target, $node);
  }

  switch($file->getType()) {
    case 'image':
      $suggestion = '[IMG|showtag:' . $target . ']';
      break;

    default:
      $suggestion = '[FILE|showtag:' . $target . ']';
      break;
  }

  return $suggestion;
}

