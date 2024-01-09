<?php

namespace Quanta\Common;

/**
 * Class NodeFactory
 *
 * This Factory class contains static methods for loading, manipulating, saving
 * and deleting Nodes. Also serves as a rendering tool for loading and
 * applying Node Templates.
 *
 */
class NodeFactory {
  /**
   * Load a node, by checking access and running hooks.
   *
   * @param Environment $env
   *   The Environment.
   * @param $node_name
   *   The name of the node to be loaded.
   * @param string $language
   *   The language in which to load the node.
   *
   * @return Node
   *   The built node object.
   */
  public static function load(Environment $env, $node_name, $language = NULL, $force_reload = TRUE, $classname = 'Node') {
    static $loaded_nodes;
    // Allow static caching of nodes. The factory doesn't load the same node two times.
    if (!$force_reload && !empty($loaded_nodes[$node_name])) {
      return $loaded_nodes[$node_name];
    }

    if (empty($language)) {
      $language = Localization::getLanguage($env);
    }

    $node = new Node($env, $node_name, NULL, $language);
    $vars = array('node' => &$node);
    $env->hook('node_open', $vars);
    $loaded_nodes[$node_name] = $node;
    return $node;
  }

  /**
   * Helper function, loading a node if its name is not empty, returning the current
   * node otherwise.
   *
   * @param Environment $env
   *   The Environment.
   * @param $node_name
   *   The name of the node to be loaded.
   * @param string $language
   *   The language in which to load the node.
   *
   * @return Node
   *   The built node object.
   */
  public static function loadOrCurrent($env, $node, $language = NULL) {
    return empty($node) ? NodeFactory::current($env) : NodeFactory::load($env, $node, $language);
  }

  /**
   * Create a "Forbidden" empty node.
   *
   * @param $env
   *   The Environment.
   *
   * @return Node
   *   A "forbidden" Node.
   */
  public static function buildForbiddenNode($env) {
    $node = new Node($env, NULL);
    $node->forbidden = TRUE;
    $node->exists = TRUE;
    $node->setBody(t('Forbidden'));
    return $node;
  }

  /**
   * Load a node from its path.
   *
   * @param Environment $env
   *   The Environment.
   * @param $path
   *   The real system path of the node.
   *
   * @return Node
   *   The built node object.
   */
  public static function loadFromRealPath(Environment $env, $path, $language = NULL) {
    $exp = explode('/', $path);
    $node_name = $exp[count($exp) - 2];
    if (empty($language)) {
      $language = Localization::getLanguage($env);
    }
    $node = new Node($env, $node_name, NULL, $language, $path);

    $vars = array('node' => &$node);
    $env->hook('node_open', $vars);
    return $node;

  }

  /**
   * Create an empty node.
   *
   * @param $env
   *   The Environment.
   * @param $father
   *   The node father (where the new empty node will be created).
   *
   * @return Node
   *   The node object.
   */
  public static function buildEmptyNode($env, $father) {
    $node = new Node($env, \Quanta\Common\Node::NODE_NEW, $father);
    return $node;
  }

  /**
   * Remove an existing symlink between nodes.
   *
   * @param Environment $env
   *   The Environment.
   * @param $symlink_name
   *   The name of the symlink to be removed.
   * @param $symlink_folder
   *   The Folder (node) where the symlink is located.
   * @param $vars
   *  Mixed variables.
   */
  public static function unlinkNodes($env, $symlink_name, $symlink_folder, $vars = array()) {
    $symlink_folder_node = NodeFactory::load($env, $symlink_folder);
    // Set the behavior to adopt if the symlink already exists.
    $if_not_exists = isset($vars['if_not_exists']) ? $vars['if_not_exists'] : 'error';

    if (!$symlink_folder_node->exists) {
      new Message($env,
        t('Error: could not unlink !symlink_name from !symlink_folder. !symlink_folder doesn\'t exist',
          array('!symlink_name' => $symlink_name, '!symlink_folder' => $symlink_folder)
        ),
        \Quanta\Common\Message::MESSAGE_ERROR
      );
    }
    elseif (!is_link($symlink_folder_node->path . '/' . $symlink_name)) {
      switch ($if_not_exists) {
        case 'error':
          new Message($env,
            t('Error: could not unlink !symlink_name from !symlink_folder. !symlink_folder/!symlink_name doesn\'t exist',
              array('!symlink_name' => $symlink_name, '!symlink_folder' => $symlink_folder)
            ),
            \Quanta\Common\Message::MESSAGE_ERROR
          );
          break;

        case 'ignore':
          break;
      }
    }
    else {
      try {
        unlink($symlink_folder_node->path . '/' . $symlink_name);
      } catch (Exception $ex) {
        new Message($vars['env'], 'Error: could not unlink ' . $symlink_name . ' from ' . $symlink_folder, \Quanta\Common\Message::MESSAGE_ERROR);
      }
    }
  }

  /**
   * Create a symlink to a node inside a specified folder.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param $source_node
   *   The source node.
   *
   * @param $symlink_folder
   *   The folder to link to.
   *
   * @param array $vars
   *   Mixed variables
   *
   * @return bool
   *   True if the linking process was OK.
   */
  public static function linkNodes($env, $source_node, $symlink_folder, $vars = array()) {
    // If no name is set for the symlink, use the source node name as default.
    $symlink_name = isset($vars['symlink_name']) ? $vars['symlink_name'] : $source_node;

    // Set the behavior to adopt if the symlink already exists.
    $if_exists = isset($vars['if_exists']) ? $vars['if_exists'] : 'error';

    $linked_ok = FALSE;

    $from_node = NodeFactory::load($env, $source_node);
    $symlink_folder_node = NodeFactory::load($env, $symlink_folder);

    $create_link = FALSE;

    // Check that source nodes and destination folder do actually exist.
    if (!$from_node->exists) {
      new Message($env, 'Error: could not link ' . $source_node . ' into ' . $symlink_folder . '. ' . $source_node . ' doesn\'t exist', \Quanta\Common\Message::MESSAGE_ERROR);
    }
    elseif (!$symlink_folder_node->exists) {
      new Message($env, 'Error: could not link ' . $source_node . ' into ' . $symlink_folder . '. ' . $symlink_folder . ' doesn\'t exist', \Quanta\Common\Message::MESSAGE_ERROR);
    }
    // What to do if the symlink exists already.
    elseif (is_link($symlink_folder_node->path . '/' . $symlink_name)) {

      switch ($if_exists) {
        case 'error':
          new Message($env, 'Error: could not link ' . $source_node . ' into ' . $symlink_folder . '. ' . $symlink_folder . '/' . $symlink_name . ' already exists.', \Quanta\Common\Message::MESSAGE_ERROR);
          break;

        case 'ignore':
          break;

        case 'override':
          unlink($symlink_folder_node->path . '/' . $symlink_name);
          $create_link = TRUE;
          break;
      }
    }
    else {
      $create_link = TRUE;

    }

    // All circumnstances are good to create the symlink. Try it.
    if ($create_link) {
      try {
        symlink($from_node->path, $symlink_folder_node->path . '/' . $symlink_name);
        $linked_ok = TRUE;
      } catch (Exception $ex) {
        new Message($vars['env'], 'Error: could not link ' . $source_node . ' to ' . $symlink_folder, \Quanta\Common\Message::MESSAGE_ERROR);
      }
    }

    return $linked_ok;
  }

  /**
   * Updates a tpl file.
   *
   * @param Environment $env
   *   The environment.
   *
   * @param string $tpl_file
   *   The path of the tpl.
   *
   * @param string $tpl_contents
   *   The content of the tpl.
   */
  public static function updateTemplate($env, $tpl_file, $tpl_contents) {
    // Check if the template file exists. Throw a message if it doesn't.
    if (!is_file($tpl_file)) {
      new Message($env, 'Warning: trying to update a non-existing template: ' . $tpl_file, \Quanta\Common\Message::MESSAGE_ERROR);
    }
    else {
      // Write the tpl file.
      $f = fopen($tpl_file, 'w');
      fwrite($f, $tpl_contents);
      fclose($f);
    }
  }

  /**
   * Create a node with basic values.
   * @param $env
   * @param $name
   * @param $father
   * @param array $vars
   * @return Node
   * @internal param $node
   */
  public static function buildNode($env, $name, $father, $vars = array()) {
    $node = NodeFactory::buildEmptyNode($env, $father);

    if (empty($vars['skip_normalize'])) {
      $name = \Quanta\Common\Api::normalizePath($name);
    }
    $node->setName($name);

    foreach ($vars as $field_name => $field_value) {
      switch ($field_name) {
        case 'title':
          $node->setTitle($field_value);
          break;

        case 'body':
          $node->setBody($field_value);
          break;

        case 'language':
          $node->setLanguage($field_value);
          break;

        case 'status':
          $node->setStatus($field_value);
          break;

        case 'author':
          $node->setAuthor($field_value);
          break;

        case 'timestamp':
          $node->setTimestamp($field_value);
          break;

        case 'weight':
          $node->setWeight($field_value);
          break;

        default:
          $node->json->{$field_name} = $field_value;
          break;
      }
    }
    if (empty($node->getTimestamp())) {
      $node->setTimestamp(time());
    }

    $node->save();
    return $node;
  }

  /**
   * Gets the current viewed node.
   *
   * @param Environment $env
   *  The Environment.
   * @param bool $reload
   *  If TRUE, the loaded node will not be loaded from cache.
   *
   * @return Node
   *   The currently viewed node.
   */
  public static function current(Environment $env, $reload = FALSE) {
    static $current_node;

    if (!empty($current_node) && !$reload) {
      // Do nothing. Load from static cache.
    }
    elseif ($env->getContext() == \Quanta\Common\Node::NODE_ACTION_ADD) {
      // Special case when we are in a "new node" add context.
      $current_node = NodeFactory::buildEmptyNode($env, $env->getRequestedPath());
    }
    elseif ($env->getContext() == 'qtag') {
      $current_node = NodeFactory::buildEmptyNode($env, NULL);
    }
    // We need to load the current node just once.
    else {
      $current_node = NodeFactory::load($env, $env->getRequestedPath());
    }
    return $current_node;
  }

  /**
   * Request to perform an action on the node. Checks permissions, builds the node
   * object and executes the action accordingly.
   *
   * @param $env Environment
   * @param $action string
   * @param $form_data
   * @return string
   */
  public static function requestAction(Environment $env, $action, array $form_data) {
    // TODO: this is needed with new approach.
    foreach ($form_data as $k => $v) {
      if (is_array($form_data[$k]) && (count($form_data[$k]) == 1)) {
        $form_data[$k] = array_pop($v);
      }
    }
    // Prepare the response object.
    $response = new \stdClass();
    $user = UserFactory::current($env);

    // When user didn't enter a path for a new node, create a candidate
    // path based on title.
    if (($action == \Quanta\Common\Node::NODE_ACTION_ADD)) {
      if (trim($form_data['edit-path']) == '') {
        $node_name = $env->getCandidatePath($form_data['edit-title']);
        $father = $form_data['edit-father'];
      }
      else {
        $node_name = $form_data['edit-path'];
        $father = NULL;
      }
    }
    else {
      $node_name = $form_data['edit-path'];
    }

    // Check the father of the node.
    $father = ($action == \Quanta\Common\Node::NODE_ACTION_ADD) ? $form_data['edit-father'] : NULL;
    $node = new Node($env, $node_name, $father);

    // Setup the after-save redirect.
    if (isset($form_data['redirect'])) {
      $node->setData('redirect', $form_data['redirect']);
    }

    // Perform the requested action.
    switch ($action) {
      case \Quanta\Common\Node::NODE_ACTION_ADD:
      case \Quanta\Common\Node::NODE_ACTION_EDIT:
        if ($action == \Quanta\Common\Node::NODE_ACTION_ADD) {
          $check_access = $node->father;
          // Setup the path of the node to be created / updated.
          $node->path = $node->father->path . '/' . $node_name;
          $node->setAuthor($user->getName());
        }
        else {
          $check_access = $node;
        }

        // Check if the user can access node add / edit for this node.
        $access_check = (NodeAccess::check($env, $action, array('node' => $check_access)));
        if ($access_check) {
          if (isset($form_data['edit-title'])) {
            // Setup all node data (title, Body, etc.)
            $node->setTitle($form_data['edit-title']);
          }
          if (isset($form_data['edit-content'])) {
            $node->setBody($form_data['edit-content']);
          }
          if (isset($form_data['edit-author'])) {
            $node->setAuthor($form_data['edit-author']);
          }
          if (isset($form_data['edit-teaser'])) {
            $node->setTeaser($form_data['edit-teaser']);
          }
          if (isset($form_data['edit-weight'])) {
            $node->setWeight($form_data['edit-weight']);
          }
          if (isset($form_data['edit-date'])) {
            $datetime = strtotime($form_data['edit-date'] . ' ' . $form_data['edit-time']);
            $node->setTimestamp($datetime > 0 ? $datetime : time());
          }
          // Also setup the temporary file directory for the upload.
          if (isset($form_data['tmp_files_dir'])) {
            $node->setData('tmp_files_dir', $form_data['tmp_files_dir']);
          }
          // TODO complete validation code.
          if (!empty($form_data['edit-thumbnail'])) {
            $node->setThumbnail(Api::normalizeFilePath($form_data['edit-thumbnail']));
          }

          $vars = array(
            'node' => &$node,
            'data' => $form_data,
            'action' => $action,
          );
          // Run the node presave hook.
          $env->hook('node_presave', $vars);

          // If the node is validated, proceed with saving it.
          if ($node->validate()) {
            $node->save();
            // Hook node_add_complete, node_edit_complete, etc.
            $env->hook('node_after_save', $vars);
            // Hook node_add_complete, node_edit_complete, etc.
            $env->hook($action . '_complete', $vars);
	    // If the form has a redirect field, setup a redirect.
            $response->redirect = !empty($form_data['redirect']) ? $form_data['redirect'] : ('/' . $node->getName());
          }
          else {
            // TODO: make this good.
            $response->errors = Message::burnMessages();
          }
        }
        else {
          // Access denied.
          $response->redirect = '/403';
        }

        break;

      // User requested to delete a Node...
      case
      \Quanta\Common\Node::NODE_ACTION_DELETE:
        // Check that the current user has the right to delete the node.
        $has_access = (NodeAccess::check($env, \Quanta\Common\Node::NODE_ACTION_DELETE, array('node' => $node)));
        if ($has_access) {
          // Delete the node...
          $node->delete();
          // ...and display a confirmation message.
          new Message($env, t('!node was deleted correctly', array('!node' => $node->getTitle())));
          $response->redirect = !empty($form_data['redirect']) ? $form_data['redirect'] : ('/' . $node->getFather()->getName() . '/');
        }
        else {
          $response->redirect = '/403';
        }
        break;
    }

    // Encode the response JSON code.
    $response_json = json_encode($response);

    return $response_json;
  }

  /**
   * Render a node, by building its template.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param $node_name
   *   The name of the node to be rendered..
   *
   * @return string
   *   The rendered HTML of the node object.
   */
  public static function render(Environment $env, $node_name = NULL, $language = NULL) {
    $node = empty($node_name) ? NodeFactory::current($env) : NodeFactory::load($env, $node_name);
    $tpl = new NodeTemplate($env, $node);
    return $tpl->getHtml();
  }
}
