<?php
/**
 * @return mixed
 */
function wiki_AUTHOR($attributes, $var, $string, $env) {
  $node = $env->getData('node');
  if (is_object($node)) {
    $author = $env->getData('node')->getAuthor();
    return wiki_USER($attributes, $var, $string, $env, $author);
  }
}

function wiki_BLOCK($attributes, $node, $string, $env) {
  $attributes['name'] = 'content';
  return Node::wrap($node, wiki_ATTRIBUTE($attributes, $node, $string, $env));
}

function wiki_LINK($attributes, $node, $string, $env) {
  $node = new Node($env, $node);
	$normalized = string_normalize($node->name);
  $link = '<a class="link link-' . $normalized . '" href="/' . $normalized . '">' . string_normalize((isset($attributes['title']) ? $attributes['title'] : $node->title)) .'</a>';
  return $link;

}

function wiki_ATTRIBUTE($attributes, $node, $string, $env) {

  $node = empty($node) ? $env->getData('node') : new Node($env, $node);

  switch($attributes['name']) {
    case 'name':
      $string = $node->name;
    break;

    case 'title':
      $string = $node->title;
      break;

    case 'content':
      // TODO: why sometimes template it's not built yet?
      $node->buildTemplate();
      $string = $node->render();
      break;

    case 'body':
      $string = $node->getBody();
      break;

    case 'teaser':
      $string = $node->getTeaser();
      break;

    case 'father':
      $string = $node->getFather()->getName();
      break;

    case 'time':
      $string = $node->getTime();
      break;

    case 'date':
      $string = $node->getDate();
      break;

    case 'tmp_files_dir':
      $string = $node->tmp_files_dir;
      break;

    case 'thumbnail':
      $string = $node->getThumbnail();
      break;
  }

  return '<span class="node-item-attribute" type="' . $attributes['name'] . '" rel="' . $node->getName() . '">' . $string . '</span>';
}

function wiki_ADD($attributes, $father, $string, $env) {
  $father = empty($father) ? $env->getData('node') : new Node($env, $father);
  $type = isset($attributes['type']) ? $attributes['type'] : 'node';
  $widget = isset($attributes['widget']) ? $attributes['widget'] : 'tabs';
  $label = isset($attributes['label']) ? $attributes['label'] : 'add';
  return '<a href="#" data-type="' . $type . '" data-widget = "' . $widget . '" class="add-link" rel="' . $father->name . '">' . $label . '</a>';
}

function wiki_EDIT($attributes, $node, $string, $env) {
  $node = empty($node) ? $env->getData('node') : new Node($env, $node);
  $type = isset($attributes['type']) ? $attributes['type'] : 'node';
  $widget = isset($attributes['widget']) ? $attributes['widget'] : 'tabs';
  $label = isset($attributes['label']) ? $attributes['label'] : 'edit';
  return '<a href="#" data-type="' . $type . '" data-widget = "' . $widget . '" class="edit-link" rel="' . $node->name . '">' . $label . '</a>';
}

function wiki_DELETE($attributes, $node, $string, $env) {
  $node = empty($node) ? $env->getData('node') : new Node($env, $node);
  $type = isset($attributes['type']) ? $attributes['type'] : 'node';
  $widget = isset($attributes['widget']) ? $attributes['widget'] : 'tabs';
  $label = isset($attributes['label']) ? $attributes['label'] : 'delete';
  return '<a href="#" data-type="' . $type . '" data-widget = "' . $widget . '" class="delete-link" rel="' . $node->name . '">' . $label . '</a>';
}

function wiki_OPERATIONS($attributes, $node, $string, $env) {
  $operations = '';
	$operations .= wiki_ADD($attributes, $node, $string, $env);
	$operations .= wiki_EDIT($attributes, $node, $string, $env);
	$operations .= wiki_DELETE($attributes, $node, $string, $env);
  return $operations;
}


function wiki_CATEGORIES($tagname, $var, $string, $env) {
  $node = $env->getData('node');
  $html = '';
  $cats = $node->getCategories($var);
  foreach ($cats as $cat) {
    $html .= '<div>[' . $cat->name . ':' . $cat->title . ']</div>';
  }
  return $html;
}

