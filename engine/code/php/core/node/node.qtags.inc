<?php
/**
 * Implements qtag AUTHOR.
 *
 * Renders the author of a node as an user link.
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
function qtag_AUTHOR($env, $target, $attributes) {
  $node = NodeFactory::current($env);
  $author = $node->getAuthor();
  return qtag_LINK($env, $author, $attributes);
}

/**
 * Implements qtag BLOCK.
 *
 * Renders a node as a block that can be embedded elsewhere.
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
function qtag_BLOCK($env, $target, $attributes) {
  $node = NodeFactory::load($env, $target);

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
 * Implements of qtag ATTRIBUTE.
 *
 * Very important tag that allows retrieving every standard attribute of a node.
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
function qtag_ATTRIBUTE($env, $target, $attributes) {

  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);

  // Check if the node shall be rendered before gathering attributes.
  if (isset($attributes['rendered'])) {
    $node->buildTemplate();
  }

  $perm = NODE_ACTION_VIEW;
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
      $perm = NODE_ACTION_EDIT;
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
      $node->buildTemplate();
      $perm = NODE_ACTION_EDIT_TPL;
      $string = $node->getData('tpl_file');
      break;

    // Node's template file.
    case 'tpl':
      $node->buildTemplate();
      $perm = NODE_ACTION_EDIT_TPL;
      $string = (!empty($node->getData('tpl_file'))) ? file_get_contents($node->getData('tpl_file')) : '';
      break;

    default:
      $string = '';
    new Message($env, 'Error: trying to fetch the invalid attribute ' . $attributes['name'], MESSAGE_WARNING);
      break;
  }

  return $string;
}

/**
 * Implements qtag LINK.
 *
 * This tag creates a link to another node in the system.
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
 *
 * TODO: we should make Links an object oriented / class item.
 */
function qtag_LINK($env, $target, $attributes) {
  $querystring = array();
  $link_title = '';
  $classes = array('link');

  // Check if the target is a node or an external link.
  if (((substr($target, 0, 4) == 'http') || (substr($target, 0, 4) == 'www.'))) {
    $classes[] = 'link-ext';
    $title_default = $target;
    $attributes['rel'] = NULL;
  }
  else {
    // Load the target node.
    $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
    // Retrieve main link attributes.
    // TODO: use something to get the real url...
    $vars = array('target' => $target);
    // Allow other modules to change the URL.
    $env->hook('link_alter', $vars);
    $target = '/' . $node->getName();
    // TODO: loading the full node at each link is not performant.
    $title_default = $node->getTitle();
    // Add classes...
    $classes[] = 'link-' . string_normalize($node->getName());
    // Check if this node's link is identical to the current node.
    if (($target . '/') == $env->request_uri) {
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

  $link_data = array();
  // TODO: make just a big variable "data".
  // Check Quanta data types.
  $data_types = array('rel', 'language', 'type', 'widget', 'components', 'tooltip', 'redirect');
  foreach ($data_types as $data_type) {
    if (isset($attributes[$data_type])) {
      $link_data[] = 'data-' . $data_type . '="' . $attributes[$data_type] . '"';
    }
  }

  $query = (!empty($querystring)) ? ('?' . implode('&', $querystring)) : '';

  $title = string_normalize((isset($attributes['title']) ? $attributes['title'] : $title_default));
  // Create the link HTML.
  $link = '<a ' . implode(' ', $link_data) . ' class="' . implode(' ', $classes) . '" ' . $link_title . ' href="' . $target . $query . '">' . $title . '</a>';
  return $link;
}


// TODO: very redundant functions!
/**
 * Implementation of qtag ADD.
 *
 * Creates an "add node" button.
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
function qtag_ADD($env, $target, $attributes) {
  $nodeobj = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $link = '';
  // Check if the user has the permission to add a node.
  if (NodeAccess::check($env, NODE_ACTION_ADD, array('node' => $nodeobj))) {
    $attributes['class'] = isset($attributes['class']) ? $attributes['class'] : '';
    $attributes['class'] .= ' add-link ';
    $attributes['tooltip'] = isset($attributes['tooltip']) ? $attributes['tooltip'] : 'Add to <i>' . $nodeobj->getTitle() . '</i>...';
    $attributes['language'] = isset($attributes['language']) ? $attributes['language'] : Localization::getLanguage($env);
    $attributes['title'] = isset($attributes['title']) ? $attributes['title'] : '&oplus;';
    $link = qtag_LINK($env, $target, $attributes);
  }
  return $link;
}

/**
 * Implementats qtag EDIT.
 *
 * Creates an "edit node" button.
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
function qtag_EDIT($env, $target, $attributes) {
  $nodeobj = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $string = '';
  if (NodeAccess::check($env, NODE_ACTION_EDIT, array('node' => $nodeobj))) {

    $attributes['class'] = isset($attributes['class']) ? $attributes['class'] : '';
    $attributes['class'] .= ' edit-link';
    $title = empty($nodeobj->getTitle()) ? $nodeobj->getName() : $nodeobj->getTitle();
    $attributes['tooltip'] = isset($attributes['tooltip']) ? $attributes['tooltip'] : 'Edit <i>' . $title . '</i>...';
    //$attributes['language'] = isset($attributes['language']) ? $attributes['language'] : $env->getLanguage();

    $attributes['title'] = isset($attributes['title']) ? $attributes['title'] : '&#9998;';
    $attributes['redirect'] = isset($attributes['redirect']) ? $attributes['redirect'] : '';

    $string = qtag_LINK($env, $target, $attributes);
  }
  return $string;
}

/**
 * Implements qtag DELETE.
 *
 * Creates a "delete node" button.
 *
 * @see qtag()
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
function qtag_DELETE($env, $target, $attributes) {
  $nodeobj = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $string = '';
  if (NodeAccess::check($env, NODE_ACTION_DELETE, array('node' => $nodeobj))) {

    $attributes['class'] = isset($attributes['class']) ? $attributes['class'] : '';
    $attributes['class'] .= 'delete-link';
    $attributes['title'] = isset($attributes['title']) ? $attributes['title'] : '&ominus;';
    $attributes['tooltip'] = isset($attributes['tooltip']) ? $attributes['tooltip'] : 'Delete <i>' . $nodeobj->getTitle() . '</i>...';

    $string = qtag_LINK($env, $target, $attributes);
  }
  return $string;
}

/**
 * Implements qtag OPERATIONS.
 *
 * Simply renders ADD - EDIT - DELETE tags altogether.
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
function qtag_OPERATIONS($env, $target, $attributes) {
  $operations = '';
  // Collect the three basic admin links.
  $operations .= qtag_ADD($env, $target, $attributes);
  $operations .= qtag_EDIT($env, $target, $attributes);
  $operations .= qtag_DELETE($env, $target, $attributes);
  return $operations;
}

/**
 * Implements qtag CATEGORIES.
 *
 * Returns links to all nodes that contain a symlink to this node.
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
function qtag_CATEGORIES($env, $target, $attributes) {
  $node = NodeFactory::current($env);
  $html = '';
  $cats = $node->getCategories($target);
  foreach ($cats as $cat) {
    $html .= '[LINK:' . $cat->name . ']';
  }
  return $html;
}

/**
 * Implements qtag COUNT.
 *
 * @deprecated Count the number of subdirectories of a node.
 * TODO recheck.
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
function qtag_COUNT($env, $target, $attributes) {
  $nodeobj = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);

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
 * Implements qtag STATUS.
 *
 * Returns the status of a node.
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
function qtag_STATUS($env, $target, $attributes) {
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $status = $node->getStatus();
  $status_node = NodeFactory::load($env, $status);
  return $status_node->getTitle();
}


/**
 * Implements qtag TITLE.
 *
 * Returns the title of a node.
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
function qtag_TITLE($env, $target, $attributes) {
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  return '<h1>' . $node->getTitle() . '</h1>';
}


/**
 * Implements qtag BODY.
 *
 * Returns the body of a node.
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
function qtag_BODY($env, $target, $attributes) {
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  return $node->getBody();
}


/**
 * Implements qtag CONTENT.
 *
 * Returns the full rendered content of a node.
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
function qtag_CONTENT($env, $target, $attributes) {
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $node->buildTemplate();
  return $node->render();  
}