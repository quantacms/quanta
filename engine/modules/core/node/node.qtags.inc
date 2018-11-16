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
function qtag_AUTHOR($env, $target, &$attributes) {
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $author = $node->getAuthor();
  return qtag_LINK($env, $author, $attributes);
}

/**
 * Implements of qtag ATTRIBUTE.
 *
 * Allows retrieving standard attributes of a node.
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
function qtag_ATTRIBUTE($env, $target, &$attributes) {

  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
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

    // Node's title.
    case 'title':
      $string = filter_xss($node->getTitle());
      break;

    // Node's full rendered content.
    case 'content':
      $string = NodeFactory::render($env, $node);
      break;

    // Node's body.
    case 'body':
      $string = $node->getBody();
      break;

    // Node's teaser.
    case 'teaser':
      $string = filter_xss($node->getTeaser());
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

    default:
      $string = '';
    new Message($env, 'Error: trying to fetch the invalid attribute ' . $attributes['name'], MESSAGE_WARNING);
      break;
  }

  // TODO check permission ($perm).

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
 * TODO: we should really make Link a class.
 */
function qtag_LINK($env, $target, &$attributes) {
  $querystring = array();
  $link_target = '';
  $title_default = '';
  $link_data = array();
  $link_id = !empty($attributes['link_id']) ? $attributes['link_id'] : '';

  // Add support for protocol (http, https etc.).
  // TODO: Find a better solution for &colon; instead of : to avoid conflicts in multiple qtags.
  $protocol = !(empty($attributes['protocol'])) ? ($attributes['protocol'] . '&colon;//') : '';

  $classes = array('link');

  // Check if the target is a node or an external link.
  if (((substr($target, 0, 4) == 'http') || (substr($target, 0, 4) == 'www.'))) {
    $classes[] = 'link-ext';
    $title_default = filter_xss($target);
    $attributes['rel'] = NULL;
    $attributes['external'] = TRUE;
  }
  else {
    $attributes['external'] = FALSE;

    // Load the target node.
    if (empty($target)) {
      // Set empty href for link.
      $target = '#';
    } else {
      // Load the node.
      $node = NodeFactory::load($env, $target);
    
      // Retrieve main link attributes.
      // TODO: use something to get the real url...
      // Allow other modules to change the URL.
      $target = '/' . $node->getName();
      // TODO: loading the full node at each link is not performant.
      $title_default = filter_xss($node->getTitle());
      // Add classes...
      $classes[] = 'link-' . string_normalize($node->getName());
      // Check if this node's link is identical to the current node.
      if (($target . '/') == $env->request_uri) {
        $classes[] = 'link-active';
      }
      $attributes['rel'] = $node->getName();    
    }
  }
  // Set the text of the link.
  $title = isset($attributes['title']) ? $attributes['title'] : $title_default;
  // Check if there is a link title.
  $link_title = isset($attributes['link_title']) ? $attributes['link_title'] : strip_tags($title);

  $vars = array('target' => &$target, 'attributes' => &$attributes, 'link_data' => &$link_data);
  $env->hook('link_alter', $vars);

  // Add custom classes to the link.
  if (isset($attributes['link_class'])) {
    $classes[] = $attributes['link_class'];
  }

  // Check if there is a target language.
  if (isset($attributes['language'])) {
    $querystring[] = 'lang=' . $attributes['language'];
  }

  // Check if there is "target" attribute.
  if (isset($attributes['link_target'])) {
    $link_target = 'target="' . $attributes['link_target'] . '"';
  }

  // TODO: make just a big variable "data".
  // Check Quanta data types.
  $data_types = array('rel', 'language', 'type', 'widget', 'components', 'tooltip', 'redirect');
  foreach ($data_types as $data_type) {
    if (isset($attributes[$data_type])) {
      $link_data[] = 'data-' . $data_type . '="' . $attributes[$data_type] . '"';
    }
  }

  // Check if link has an ID.
  $id = !empty($link_id) ? ('id="' . $link_id . '"') : '';
  // Sets a query string.
  $query = (!empty($querystring)) ? ('?' . implode('&', $querystring)) : '';

  // Create the link HTML.
  $link = '<a ' . $id . ' ' . implode(' ', $link_data) . ' class="' . implode(' ', $classes) . '" title="' . $link_title . '" href="' . $protocol . $target . $query . '" ' . $link_target . '>' . $title . '</a>';
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
function qtag_ADD($env, $target, &$attributes) {
  $nodeobj = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $target = $nodeobj->getName();
  $link = '';
  // Check if the user has the permission to add a node.
  if (NodeAccess::check($env, NODE_ACTION_ADD, array('node' => $nodeobj))) {
    $attributes['link_class'] = isset($attributes['add_class']) ? $attributes['add_class'] : '';
    $attributes['link_class'] .= ' add-link ';
    $attributes['tooltip'] = isset($attributes['tooltip']) ? filter_xss($attributes['tooltip']) : 'Add to ' . filter_xss($nodeobj->getTitle()) . '...';
    $attributes['language'] = isset($attributes['language']) ? $attributes['language'] : Localization::getLanguage($env);
    $attributes['title'] = isset($attributes['title']) ? filter_xss($attributes['title']) : '&oplus;';


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
function qtag_EDIT($env, $target, &$attributes) {
  $nodeobj = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $target = $nodeobj->getName();
  $string = '';
  if (NodeAccess::check($env, NODE_ACTION_EDIT, array('node' => $nodeobj))) {

    $attributes['link_class'] = isset($attributes['edit_class']) ? $attributes['edit_class'] : '';
    $attributes['link_class'] .= ' edit-link';
    $title = filter_xss(empty($nodeobj->getTitle()) ? $nodeobj->getName() : $nodeobj->getTitle());

    $attributes['tooltip'] = isset($attributes['tooltip']) ? filter_xss($attributes['tooltip']) : 'Edit ' . $title . '...';
    //$attributes['language'] = isset($attributes['language']) ? $attributes['language'] : $env->getLanguage();

    $attributes['title'] = isset($attributes['title']) ? filter_xss($attributes['title']) : '&#9998;';
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
function qtag_DELETE($env, $target, &$attributes) {
  $nodeobj = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $target = $nodeobj->getName();
  $string = '';
  if (NodeAccess::check($env, NODE_ACTION_DELETE, array('node' => $nodeobj))) {

    $attributes['link_class'] = isset($attributes['delete_class']) ? $attributes['delete_class'] : '';
    $attributes['link_class'] .= 'delete-link';
    $attributes['title'] = isset($attributes['title']) ? filter_xss($attributes['title']) : '&ominus;';
    $attributes['tooltip'] = isset($attributes['tooltip']) ? filter_xss($attributes['tooltip']) : 'Delete ' . filter_xss($nodeobj->getTitle()) . '...';

    $attributes['redirect'] = isset($attributes['redirect']) ? $attributes['redirect'] : '';

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
function qtag_OPERATIONS($env, $target, &$attributes) {
  $operations = '';
  // TODO: now that we are passing attributes by reference, we can't reuse it easily here.
  $empty_attributes = array();
  $operations .= qtag_ADD($env, $target, $empty_attributes);
  $empty_attributes = array();
  $operations .= qtag_EDIT($env, $target, $empty_attributes);
  $empty_attributes = array();
  $operations .= qtag_DELETE($env, $target, $empty_attributes);
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
function qtag_CATEGORIES($env, $target, &$attributes) {
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
function qtag_COUNT($env, $target, &$attributes) {
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
  $count_cmd = 'find ' . $nodeobj->path . ' ' . $depth . ' -type d | wc -l';
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
function qtag_STATUS($env, $target, &$attributes) {
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $status = $node->getStatus();
  $status_node = NodeFactory::load($env, $status);
  return $status_node->getTitle();
}


/**
 * Implements qtag TEASER.
 *
 * Returns the teaser of a node.
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
function qtag_TEASER($env, $target, &$attributes) {
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $teaser = filter_xss($node->getTeaser());
  if (isset($attributes['editable'])) {
    $teaser = NodeTemplate::wrap($env, $node, $teaser);
  }
  return $teaser;
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
function qtag_TITLE($env, $target, &$attributes) {
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $title = filter_xss($node->getTitle());
  if (isset($attributes['editable'])) {
    $title = NodeTemplate::wrap($env, $node, $title);
  }
  return $title;
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
function qtag_BODY($env, $target, &$attributes) {
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  // TODO: breaks the HTML, but we definitely need a xss filter for this tag.
  // $body = filter_xss($node->getBody());
  $body = $node->getBody();
  if (isset($attributes['editable'])) {
    $body = NodeTemplate::wrap($env, $node, $body);
  }
  return $body;
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
function qtag_CONTENT($env, $target, &$attributes) {
  $node = NodeFactory::load($env, $target);
  $content = NodeFactory::render($env, $node->getName());
  if (isset($attributes['editable'])) {
    $content = NodeTemplate::wrap($env, $node, $content);
  }
  return $content;
}

/**
 * Implements qtag KEYWORDS.
 *
 * Returns the keywords of a node.
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
function qtag_KEYWORDS($env, $target, &$attributes) {
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  return $node->getKeywords();
}