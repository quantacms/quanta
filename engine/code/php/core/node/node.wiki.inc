<?php
/**
 * @return mixed
 */
function wiki_NODE($tagname, $var, $string, $page) {
  $node = $page->env->getData('node');
  if (is_object($node)) {
    switch ($var) {
      case 'content':
        $item = $node->getRawContent();
        break;

      case 'date':
        $item = $node->getDate();
        break;

      default:
        $item = '?';
        break;
    }
    return $item;
  }
}

/**
 * @return mixed
 */
function wiki_AUTHOR($tagname, $var, $string, $page) {
  $node = $page->env->getData('node');
  if (is_object($node)) {
    $author = $page->env->getData('node')->getAuthor();
    return wiki_USER($tagname, $var, $string, $page, $author);
  }
}