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

function wiki_BLOCK($attributes, $var, $string, $env) {
  $node = new Node($env, $var);
  if ($node->exists) {
    return $node->getBody();
  } else {
    new Message($env, 'Warning: ' . $var . 'is a non existing node', MESSAGE_WARNING);
  }
}

function wiki_LINK($attributes, $node, $string, $env) {
  $node = new Node($env, $node);
  $link = '<a href="/' . string_normalize($node->name) . '">' . string_normalize((isset($attributes['title']) ? $attributes['title'] : $node->title)) .'</a>';
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
      $string = $node->getContent();
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

  return $string;
}

function wiki_ADD($attributes, $father, $string, $env) {

  $father = empty($father) ? $env->getData('node') : new Node($env, $father);
  $type = isset($attributes['type']) ? $attributes['type'] : 'node';
  $widget = isset($attributes['widget']) ? $attributes['widget'] : 'tabs';
  $label = isset($attributes['label']) ? $attributes['label'] : 'POST';

  return '<a href="#" data-type="' . $type . '" data-widget = "' . $widget . '" class="add-link" rel="' . $father->name . '">' . $label . '</a>';


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

