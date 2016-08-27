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

      case NODE_ACTION_ADD:
      case NODE_ACTION_EDIT:
      case NODE_ACTION_DELETE:
        $user = User::current($page->env);
        $act = str_replace('node_', '', $var);
        if ($user->checkAccess($node, $var)) {
          $item = '<a class="' . $act . '-link" href="#">' . $act . '...</a>';
        }
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

function wiki_BLOCK($tagname, $var, $string, $page) {
  $node = new Node($page->env, $var);
  if ($node->exists) {
    return $node->getBody();
  } else {
    new Message($page->env, 'Warning: ' . $var . 'is a non existing node', MESSAGE_WARNING);
  }
}