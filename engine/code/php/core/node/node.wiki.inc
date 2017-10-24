<?php
/**
 * Implementation of wiki tag Author
 * Renders the author of a node as an user link.
 *
 * @param $attributes
 * @param $node
 * @param $string
 * @param $env
 * @return string
 */
function wiki_AUTHOR($attributes, $var, $string, $env) {
  $node = NodeFactory::current($env);
  $author = $node->getAuthor();
  return wiki_LINK($attributes, $author, $string, $env);
}

/**
 * Implementation of wiki tag BLOCK
 * Renders a node as a block that can be embedded elsewhere.
 *
 * @param $attributes
 * @param $node
 * @param $string
 * @param $env
 * @return string
 */
function wiki_BLOCK($attributes, $node, $string, $env) {
  $node = NodeFactory::load($env, $node);

  if (isset($attributes['rendered'])) {
    $node->buildTemplate();
    $body = $node->render();
  }
  else {
    $body = isset($attributes['with-title']) ? ('<h2 class="block-title">' . $node->getTitle() . '</h2>' . $node->getBody()) : $node->getBody();
  }
  
  $editable = !empty($attributes['editable']) ? $attributes['editable'] : 'true';
  if ($editable == 'true') {
    $body = $node->wrap($body);
  }
  // If user can't see the node, don't display it.
  return $node->isForbidden() ? '' : $body;
}
/**
 * Implementation of wiki tag JSONATTRIBUTE
 * Very important tag that allows retrieving every attribute of a node.
 *
 * @param $attributes
 * @param $node
 * @param $string
 * @param $env
 * @return string
 */
function wiki_JSONATTRIBUTE($attributes, $node, $string, $env) {
  $attribute = wiki_ATTRIBUTE($attributes, $node, $string, $env);
	$explode = explode(GLOBAL_SEPARATOR, $attribute);
	if (count($explode) > 1) {
		$attribute = $explode;
	}
	return json_encode($attribute);
}

/**
 * Implementation of wiki tag ATTRIBUTE
 * Very important tag that allows retrieving every attribute of a node.
 *
 * @param $attributes
 * @param $node
 * @param $string
 * @param $env
 * @return string
 */
function wiki_ATTRIBUTE($attributes, $node, $string, $env) {
  
  $node = empty($node) ? NodeFactory::current($env) : NodeFactory::load($env, $node);

  switch($attributes['name']) {
    case 'name':
      $string = $node->getName();
      break;

    case 'author':
      $string = $node->getAuthor();
      // In case node has no author, return NULL string.
      if ($string == USER_ANONYMOUS) {
        $string = '';
      }
      break;
    // TODO: still looking for a better solution for new nodes.
    case 'realname':
      $string = $node->name == NODE_NEW ? '' : $node->name;
      break;

    case 'path':
      $string = $node->path;
     break;

    case 'title':
      $string = $node->getTitle();
      break;

    case 'content':
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

    case 'status':
      $string = $node->getStatus();
      break;

    default:
      // TODO: security issues.
      if (isset($node->json->{$attributes['name']})) {
        $string = string_normalize($node->json->{$attributes['name']});
      }
      else {
        $string = '';
      }

      break;
  }

  return $string;
}


function wiki_LINK($attributes, $target, $string, $env) {
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $classes = array('link');
  $current = NodeFactory::current($env);
  $querystring = array();


  if (((substr($target, 0, 4) == 'http') || (substr($target, 0, 4) == 'www.')) && !$node->exists) {
    $classes[] = 'link-ext';
    $title_default = $target;
    $attributes['rel'] = NULL;
  } else {
    // TODO: use something to get the real url...
		$target = '/' . string_normalize($node->getName());
    $title_default = $node->title;
    $classes[] = 'link-' . string_normalize($node->getName());
    if ($current->name == $node->name) {
      $classes[] = 'link-active';
    }
    $attributes['rel'] = $node->getName();
  }

  if (isset($attributes['class'])) {
    $classes[] = $attributes['class'];
  }

  if (isset($attributes['language'])) {
    $querystring[] = 'lang=' . $attributes['language'];
  }

  $link_data = array();
  // TODO: make just a big variable "data".
  $data_types = array('rel', 'language', 'type', 'widget', 'components', 'tooltip', 'redirect');
  foreach ($data_types as $data_type) {
    if (isset($attributes[$data_type])) {
      $link_data[] = 'data-' . $data_type . '="' . $attributes[$data_type] . '"';
    }
  }

  $query = (!empty($querystring))  ? ('?' . implode('&', $querystring)) : '';


  $link = '<a ' . implode(' ', $link_data) . ' class="' . implode(' ', $classes). '" href="' . $target . $query . '">' . string_normalize((isset($attributes['title']) ? $attributes['title'] : $title_default)) .'</a>';
  return $link;
}


// TODO: very redundant functions!
/**
 * Implementation of wiki tag ADD.
 * Creates an add node button.
 *
 * @param $attributes
 * @param $node the father of the node
 * @param $string
 * @param $env
 * @return string|void
 */
function wiki_ADD($attributes, $node, $string, $env) {
  $nodeobj = empty($node) ? NodeFactory::current($env) : NodeFactory::load($env, $node);

  $user = UserFactory::current($env);
  if (!$user->checkAccess($nodeobj, NODE_ACTION_ADD)) {
    return;
  }

  $attributes['class'] = isset($attributes['class']) ? $attributes['class'] : '';
  $attributes['class'] .= 'add-link';
  $attributes['tooltip'] = isset($attributes['tooltip']) ? $attributes['tooltip'] : 'Add to <i>' . $nodeobj->getTitle() . '</i>...';
  $attributes['language'] = isset($attributes['language']) ? $attributes['language'] : $env->getLanguage();
  $attributes['title'] = isset($attributes['title']) ? $attributes['title'] : '&oplus;';
  return wiki_LINK($attributes, $node, $string, $env);
}

/**
 * Implementation of wiki tag EDIT.
 * Creates an edit node button.
 *
 * @param $attributes
 * @param $node the name of the node
 * @param $string
 * @param $env
 * @return string|void
 */
function wiki_EDIT($attributes, $node, $string, $env) {
  $nodeobj = empty($node) ? NodeFactory::current($env) : NodeFactory::load($env, $node);

  $user = UserFactory::current($env);
  if (!$user->checkAccess($nodeobj, NODE_ACTION_EDIT)) {
    return;
  }
  $attributes['class'] = isset($attributes['class']) ? $attributes['class'] : '';
  $attributes['class'] .= ' edit-link';
  $attributes['tooltip'] = isset($attributes['tooltip']) ? $attributes['tooltip'] : 'Edit <i>' . $nodeobj->getTitle() . '</i>...';
  //$attributes['language'] = isset($attributes['language']) ? $attributes['language'] : $env->getLanguage();

  $attributes['title'] = isset($attributes['title']) ? $attributes['title'] : '&#9998;';
  $attributes['redirect'] = isset($attributes['redirect']) ? $attributes['redirect'] : '';

  return wiki_LINK($attributes, $node, $string, $env);
}

/**
 * Implementation of wiki tag DELETE.
 * Creates a delete node button.
 *
 * @param $attributes
 * @param $node the name of the node
 * @param $string
 * @param $env
 * @return string|void
 */
function wiki_DELETE($attributes, $node, $string, $env) {
  $nodeobj = empty($node) ? NodeFactory::current($env) : NodeFactory::load($env, $node);

  $user = UserFactory::current($env);
  if (!$user->checkAccess($nodeobj, NODE_ACTION_DELETE)) {
    return;
  }
  $attributes['class'] = isset($attributes['class']) ? $attributes['class'] : '';
  $attributes['class'] .= 'delete-link';
  $attributes['title'] = isset($attributes['title']) ? $attributes['title'] : '&ominus;';
  $attributes['tooltip'] = isset($attributes['tooltip']) ? $attributes['tooltip'] : 'Delete <i>' . $nodeobj->getTitle() . '</i>...';
  return wiki_LINK($attributes, $node, $string, $env);
}

/**
 * Implementation of wiki tag OPERATIONS.
 * Simply renders ADD - EDIT - DELETE tags altogether.
 *
 * @param $attributes
 * @param $node
 * @param $string
 * @param $env
 * @return string
 */
function wiki_OPERATIONS($attributes, $node, $string, $env) {
  $operations = '';
  // Collect the three basic admin links.
	$operations .= wiki_ADD($attributes, $node, $string, $env);
	$operations .= wiki_EDIT($attributes, $node, $string, $env);
	$operations .= wiki_DELETE($attributes, $node, $string, $env);
  return $operations;
}

/**
 * TODO: what does this function do?
 * @param $tagname
 * @param $var
 * @param $string
 * @param $env
 * @return string
 */
function wiki_CATEGORIES($attributes, $var, $string, $env) {
  $node = NodeFactory::current($env);
  $html = '';
  $cats = $node->getCategories($var);
  foreach ($cats as $cat) {
    $html .= '[LINK:' . $cat->name . ']';
  }
  return $html;
}


/**
 * TODO: not good. It does not filter per access...
 * @param $attributes
 * @param $var
 * @param $string
 * @param $env
 * @return string
 */
function wiki_COUNT($attributes, $node, $string, $env) {
  $nodeobj = empty($node) ? NodeFactory::current($env) : NodeFactory::load($env, $node);

  $depth = '';
  if (isset($attributes['maxdepth'])) {
    $depth .= ' -maxdepth ' . $attributes['maxdepth'];
  }
  if (isset($attributes['mindepth'])) {
    $depth .= ' -mindepth ' . $attributes['mindepth'];
  } else {
    $depth .= ' -mindepth 1';
  }

  $count_cmd = 'find ' . $nodeobj->realpath . ' ' . $depth . ' -type d | wc -l';
  exec($count_cmd, $results_arr);
  return array_pop($results_arr);

}


/**
 * Implementation of wiki tag STATUS.
 *
 * @param $attributes
 * @param $node
 * @param $string
 * @param $env
 * @return string
 */
function wiki_STATUS($attributes, $node, $string, $env) {
  $node = empty($node) ? NodeFactory::current($env) : NodeFactory::load($env, $node);

  $status = $node->getStatus();
  $status_node = NodeFactory::load($env, $status);

  return $status_node->getTitle();
}