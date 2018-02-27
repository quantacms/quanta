<?php

/**
 * Class NodeFactory
 * This factory class contains only static methods, and it's used for loading nodes, etc.
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
  public static function load(Environment $env, $node_name, $language = NULL) {
    if (empty($language)) {
      $language = Localization::getLanguage($env);
    }

    $node = new Node($env, $node_name, NULL, $language);
    $vars = array('node' => &$node);

    $env->hook('node_open', $vars);
    return $node;
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
    $node->setBody('FORBIDDEN');
    return $node;
  }

  /**
   * Load a node from its realpath.
   *
   * @param Environment $env
   *   The Environment.
   * @param $realpath
   *   The real system path of the node.
   *
   * @return Node
   *   The built node object.
   */
  public static function loadFromRealPath(Environment $env, $realpath) {
    $exp = explode('/', $realpath);
    return NodeFactory::load($env, $exp[count($exp) - 2]);
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
    $node = new Node($env, NODE_NEW, $father);
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
  public static function unlinkNodes($env, $symlink_name, $symlink_folder, $vars = array ()) {
    $symlink_folder_node = NodeFactory::load($env, $symlink_folder);
    // Set the behavior to adopt if the symlink already exists.
    $if_not_exists = isset($vars['if_not_exists']) ? $vars['if_not_exists'] : 'error';

    if (!$symlink_folder_node->exists) {
      new Message($env, 'Error: could not unlink ' . $symlink_name . ' from ' . $symlink_folder . '. ' . $symlink_folder . ' doesn\'t exist');
    }
    elseif (!is_link($symlink_folder_node->realpath . '/' . $symlink_name)) {
      switch ($if_not_exists) {
        case 'error':
          new Message($env, 'Error: could not unlink ' . $symlink_name . ' from ' . $symlink_folder . '. ' . $symlink_folder . '/' . $symlink_name . ' doesn\'t exist');
          break;

        case 'ignore':
          break;
      }
    }
    else {
      try {
        unlink($symlink_folder_node->realpath . '/' . $symlink_name);
      } catch (Exception $ex) {
        new Message($vars['env'], 'Error: could not unlink ' . $symlink_name . ' from ' . $symlink_folder);
      }
    }
  }

  /**
   * Create a symlink to a node inside a specified folder.
   *
   * @param Environment $env
   *   The Environment.
   * @param $source_node
   * @param $symlink_folder
   * @param array $vars
   *   Mixed variables
   * @internal param $from The Node to be linked*   The Node to be linked
   * @internal param $folder The Folder where we place the symlink*   The Folder where we place the symlink
   * @internal param $to The name of the symlink*   The name of the symlink
   */
  public static function linkNodes($env, $source_node, $symlink_folder, $vars = array ()) {
    // If no name is set for the symlink, use the source node name as default.
    $symlink_name = isset($vars['symlink_name']) ? $vars['symlink_name'] : $source_node;

    // Set the behavior to adopt if the symlink already exists.
    $if_exists = isset($vars['if_exists']) ? $vars['if_exists'] : 'error';

    $from_node = NodeFactory::load($env, $source_node);
    $symlink_folder_node = NodeFactory::load($env, $symlink_folder);

    $create_link = FALSE;

    // Check that source nodes and destination folder do actually exist.
    if (!$from_node->exists) {

      new Message($env, 'Error: could not link ' . $source_node . ' into ' . $symlink_folder . '. ' . $source_node . ' doesn\'t exist');
    }
    elseif (!$symlink_folder_node->exists) {
      new Message($env, 'Error: could not link ' . $source_node . ' into ' . $symlink_folder . '. ' . $symlink_folder . ' doesn\'t exist');
    }
    // What to do if the symlink exists already.
    elseif (is_link($symlink_folder_node->realpath . '/' . $symlink_name)) {

      switch ($if_exists) {
        case 'error':
          new Message($env, 'Error: could not link ' . $source_node . ' into ' . $symlink_folder . '. ' . $symlink_folder . '/' . $symlink_name . ' already exists.');
          break;

        case 'ignore':
          break;

        case 'override':
          unlink($symlink_folder_node->realpath . '/' . $symlink_name);
          $create_link = TRUE;
          break;
      }
    }
    else {
      $create_link = TRUE;
    }


    if ($create_link) {
      Doctor::talk('attempt creating link...');
      try {
        symlink($from_node->realpath, $symlink_folder_node->realpath . '/' . $symlink_name);
      }
      catch (Exception $ex) {
        new Message($vars['env'], 'Error: could not link ' . $source_node . ' to ' . $symlink_folder);
      }
    }
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
      new Message($env, 'Warning: trying to update a non-existing template: ' . $tpl_file, MESSAGE_ERROR);
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
  public static function buildNode($env, $name, $father, $vars = array ()) {
    $node = new Node($env, NODE_NEW, $father);
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
   *
   * @return Node bool
   */
  public static function current(Environment $env) {
    static $current_node;
    if ($env->getContext() == NODE_ACTION_ADD) {
      $current_node = NodeFactory::buildEmptyNode($env, $env->getRequestedPath());
    }
    // We need to load the current node just once.
    else if (!isset($current_node)) {
      $tpl = isset($_REQUEST['tpl']) ? $_REQUEST['tpl'] : NULL;
      // Special case when we are in a "new node" add context.
      $current_node = NodeFactory::load($env, $env->getRequestedPath(), $tpl);
    }

    return $current_node;
  }

  /**
   * Request to perform an action on the node. Checks permissions, builds the node
   * object and executes the action accordingly.
   *
   * @param $env Environment
   * @param $action string
   * @param $nodedata
   * @return string
   */
  public static function requestAction(Environment $env, $action, $nodedata) {

    // TODO: this shit is needed with new approach.
    foreach ($nodedata as $k => $v) {
      if (is_array($nodedata[$k]) && (count($nodedata[$k]) == 1)) {
        $nodedata[$k] = array_pop($v);
      }
    }

    // Prepare the response object.
    $response = new StdClass();

    // TODO: circular dependency to user.
    $user = UserFactory::current($env);

    // TODO: refactor this stuff.
    // When user didn't enter a path for a new node, create a candidate
    // path based on title.
    if (trim($nodedata['edit-path']) == '') {
      $path = $env->getCandidatePath($nodedata['edit-title']);
    }
    else {
      $path = $nodedata['edit-path'];
    }

    // Check the father of the node.
    $father = ($action == NODE_ACTION_ADD) ? $nodedata['edit-father'] : NULL;
    $node = new Node($env, $path, $father);

    switch ($action) {
      case NODE_ACTION_ADD:
      case NODE_ACTION_EDIT:
        if ($action == NODE_ACTION_ADD) {
          // Setup the path of the node to be created / updated.
          $node->realpath = $node->father->realpath . '/' . $path;
          $node->setAuthor($user->getName());
        }
        // TODO complete validation code.
        if (isset($nodedata['edit-thumbnail'])) {
          $node->setThumbnail($nodedata['edit-thumbnail']);
        }

        $datetime = strtotime($nodedata['edit-date'] . ' ' . $nodedata['edit-time']);
        $has_access = (Access::check($env, $action, array('node' => ($action == NODE_ACTION_ADD) ? $node->father : $node)));
        if ($has_access) {
          // Setup all node data (title, Body, etc.)
          $node->setTitle($nodedata['edit-title']);
          $node->setBody($nodedata['edit-content']);
          $node->setAuthor($nodedata['edit-author']);
          $node->setTeaser($nodedata['edit-teaser']);
          $node->setContent('<h1>' . $node->getTitle() . '</h1>' . $node->getBody());
          $node->setTimestamp($datetime > 0 ? $datetime : time());
          // Also setup the temporary file directory for the upload.
          $node->setData('tmp_files_dir', $nodedata['tmp_files_dir']);
          if (isset($nodedata['redirect'])) {
            $node->setData('redirect', $nodedata['redirect']);
          }

          // Run the node presave hook.
          $env->hook('node_presave', array (
            'node' => &$node,
            'data' => $nodedata,
            'action' => $action,
          ));

          // If the node is validated, proceed with saving it.
          if ($node->validate()) {
            $node->save();

            $env->hook('node_save', array (
              'node' => &$node,
              'data' => $nodedata
            ));

            $env->hook('node_post_save', array (
              'node' => &$node,
              'data' => $nodedata
            ));

            // TODO: move elsewhere maybe?
            if (isset($nodedata['edit-tpl-file'])) {
              NodeFactory::updateTemplate($env, $nodedata['edit-tpl-file'], $nodedata['edit-tpl']);
            }
            // If the form has a redirect field, setup a redirect.
            $response->redirect = !empty($node->getData('redirect')) ? $node->getData('redirect') : ('/' . $node->getName() . '/');
          }
          else {
            // TODO: make this good.
            $response->errors = Message::burnMessages();
          }
        }
        else {
          // Page not found.
          $response->redirect = '/403';
        }

        break;

      // User requested to delete a Node...
      case NODE_ACTION_DELETE:
        // Check that the current user has the right to delete the node.
        $has_access = (Access::check($env, NODE_ACTION_DELETE, array('node' => $node)));
        if ($has_access) {
          // Delete the node...
          $node->delete();
          // ...and display a confirmation message.
          new Message($node->env, $node->getName() . ' was deleted correctly');
          $response->redirect = !empty($node->getData('redirect')) ? $node->getData('redirect') : ('/' . $node->getFather()->getName() . '/');
        }
        break;
    }

    // Encode the response JSON code.
    $response_json = json_encode($response);

    return $response_json;
  }

}
