<?php
/**
 * Implementation of qtag Author
 * Renders the author of a node as an user link.
 *
 * @param $attributes
 * @param $var
 * @param $string
 * @param $env
 * @return string
 * @internal param $node
 */
function wiki_AUTHOR($attributes, $var, $string, $env) {
  $node = NodeFactory::current($env);
  $author = $node->getAuthor();
  return wiki_LINK($attributes, $author, $string, $env);
}

/**
 * Implementation of qtag BLOCK
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
 * Implementation of qtag JSONATTRIBUTE
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

  return json_encode($attribute);
}

/**
 * Implementation of qtag ATTRIBUTE.
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

  switch ($attributes['name']) {
    // Node's name (aka name of the folder).
    case 'name':
      $string = $node->getName();
      if ($string == NODE_NEW) {
        $string = '';
      }
      break;

    // Author's name (aka name of the author's node)
    case 'author':
      $string = $node->getAuthor();
      // In case node has no author, return NULL string.
      if ($string == USER_ANONYMOUS) {
        $string = '';
      }
      break;

    // Node's path.
    case 'path':
      $string = $node->path;
      break;

    // Node's title.
    case 'title':
      $string = $node->getTitle();
      break;

    // Node's full rendered content.
    case 'content':
      $node->buildTemplate();
      $string = $node->render();
      break;

    // Node's body.
    case 'body':
      $string = $node->getBody();
      break;

    // Node's teaser.
    case 'teaser':
      $string = $node->getTeaser();
      break;

    // Node's father node.
    case 'father':
      $string = $node->getFather()->getName();
      break;

    // Node's creation time.
    case 'time':
      $string = $node->getTime();
      break;

    // Node's creation date.
    case 'date':
      $string = $node->getDate();
      break;

    // Node edit screen's temporary file upload directory.
    case 'tmp_files_dir':
      $string = $node->getData('tmp_files_dir');
      break;

    // Node's thumbnail file.
    case 'thumbnail':
      $string = $node->getThumbnail();
      break;

    // Node's status.
    case 'status':
      $string = $node->getStatus();
      break;

    // Node's template file.
    case 'tpl_file':
      $string = $node->getData('tpl_file');
      break;


    // Default behavior: pick data from the json.
    // TODO: security issues?

    default:
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

/**
 * Implementation of qtag LINK.
 * This tag creates a link to another node in the system.
 *
 * @param $attributes
 * @param $target
 * @param $string
 * @param $env
 * @return string
 */
function wiki_LINK($attributes, $target, $string, $env) {
  // Load the target node.
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);

  // Retrieve main link attributes.
  $current = NodeFactory::current($env);
  $querystring = array ();
  $link_title = '';
  $classes = array ('link');

  // Check if the target is a node or an external link.
  if (((substr($target, 0, 4) == 'http') || (substr($target, 0, 4) == 'www.')) && !$node->exists) {
    $classes[] = 'link-ext';
    $title_default = $target;
    $attributes['rel'] = NULL;
  }
  else {

    // TODO: use something to get the real url...
    $target = '/' . string_normalize($node->getName());
    $title_default = $node->getTitle();
    // Add classes...
    $classes[] = 'link-' . string_normalize($node->getName());
    if ($current->getName() == $node->getName()) {
      $classes[] = 'link-active';
    }
    $attributes['rel'] = $node->getName();
  }

  // Add custom classes to the link.
  if (isset($attributes['class'])) {
    $classes[] = $attributes['class'];
  }

  // Check if there is a link title.
  if (isset($attributes['link_title'])) {
    $link_title = 'title="' . $attributes['link_title'] . '"';
  }

  // Check if there is a target language.
  if (isset($attributes['language'])) {
    $querystring[] = 'lang=' . $attributes['language'];
  }

  $link_data = array ();
  // TODO: make just a big variable "data".
  // Check Quanta data types.
  $data_types = array ('rel', 'language', 'type', 'widget', 'components', 'tooltip', 'redirect');
  foreach ($data_types as $data_type) {
    if (isset($attributes[$data_type])) {
      $link_data[] = 'data-' . $data_type . '="' . $attributes[$data_type] . '"';
    }
  }

  $query = (!empty($querystring)) ? ('?' . implode('&', $querystring)) : '';

  // Create the link HTML.
  $link = '<a ' . implode(' ', $link_data) . ' class="' . implode(' ', $classes) . '" ' . $link_title . ' href="' . $target . $query . '">' . string_normalize((isset($attributes['title']) ? $attributes['title'] : $title_default)) . '</a>';
  return $link;
}


// TODO: very redundant functions!
/**
 * Implementation of qtag ADD.
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
  $attributes['language'] = isset($attributes['language']) ? $attributes['language'] : Locale::getLanguage($env);
  $attributes['title'] = isset($attributes['title']) ? $attributes['title'] : '&oplus;';
  return wiki_LINK($attributes, $node, $string, $env);
}

/**
 * Implementation of qtag EDIT.
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
 * Implementation of qtag DELETE.
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
 * Implementation of qtag OPERATIONS.
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
 * @param $attributes
 * @param $var
 * @param $string
 * @param $env
 * @return string
 * @internal param $tagname
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
 * @param $node
 * @param $string
 * @param $env
 * @return string
 * @internal param $var
 */
function wiki_COUNT($attributes, $node, $string, $env) {
  $nodeobj = empty($node) ? NodeFactory::current($env) : NodeFactory::load($env, $node);

  $depth = '';
  if (isset($attributes['maxdepth'])) {
    $depth .= ' -maxdepth ' . $attributes['maxdepth'];
  }
  if (isset($attributes['mindepth'])) {
    $depth .= ' -mindepth ' . $attributes['mindepth'];
  }
  else {
    $depth .= ' -mindepth 1';
  }

  $count_cmd = 'find ' . $nodeobj->realpath . ' ' . $depth . ' -type d | wc -l';
  exec($count_cmd, $results_arr);
  return array_pop($results_arr);

}


/**
 * Implementation of qtag STATUS.
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