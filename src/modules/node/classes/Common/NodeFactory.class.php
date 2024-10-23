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
  public static function load(Environment $env, $node_name, $language = NULL, $force_reload = TRUE, $classname = 'Node', $use_fallback_language = true) {
    static $loaded_nodes;

     
    // Allow static caching of nodes. The factory doesn't load the same node two times.
    if (!$force_reload && !empty($loaded_nodes[$node_name])) {
      return $loaded_nodes[$node_name];
    }

    if (empty($language)) {
      $language = Localization::getLanguage($env);
    }

    $node = new Node($env, $node_name, NULL, $language);

    $cached = Cache::get($env, 'node', $node->cacheTag());
    if ($cached) {
      $node = $cached;
      $vars = array('node' => &$node);
      $env->setData(STATS_NODE_LOADED_CACHE, ($env->getData(STATS_NODE_LOADED_CACHE, 0) + 1));

      $node->built = TRUE;
      $node->exists = TRUE;
    }

    $node->load();
   

    if (!($node->hasTranslation($language)) && $use_fallback_language) {
      $fallback = Localization::getFallbackLanguage($env);
      $node = new Node($env, $node_name, NULL, 'it');
    }
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
  public static function loadOrCurrent($env, $node, $language = NULL, $use_fallback_language = TRUE) {
    return empty($node) ? NodeFactory::current($env) : NodeFactory::load($env, $node, $language, true, 'Node', $use_fallback_language );
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
  public static function buildEmptyNode($env, $father,$lang = null) {
    $node = new Node($env, \Quanta\Common\Node::NODE_NEW, $father, $lang);
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
  public static function buildNode($env, $name, $father, $vars = array(),$lang = null) {
    $node = NodeFactory::buildEmptyNode($env, $father, $lang);

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

    // TODO language management needs many further check that language actually exists
    // As well as security checks.
    $language = isset($form_data['language']->value) ? (array_pop($form_data['language']->value)) : \Quanta\Common\Localization::LANGUAGE_NEUTRAL;
    //this array contains full form data (type,required,value)
    $full_form_data = [];
    // TODO: this is needed with new approach.
    foreach ($form_data as $k => $v) {

      if (is_array($v->value) && (count($v->value) == 1)) {
        $form_data[$k] = $v->value[0];
      }
      elseif (is_array($v->value)) {
         $form_data[$k] = $v->value;
      }
      else {

      }
      $full_form_data[$k] = $v;
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
    elseif($action == \Quanta\Common\Node::NODE_ACTION_DUPLICATE){
      $node_name = $env->getCandidatePath($form_data['edit-title']);
      $father = $form_data['edit-father'];
    }
    else {
      $node_name = $form_data['edit-path'];
      $father = isset($form_data['edit-father']) ? $form_data['edit-father'] : NULL;
    }
    $env->setData('action',$action);
    
    if($action == \Quanta\Common\Node::NODE_ACTION_DUPLICATE){
      $source_node = \Quanta\Common\NodeFactory::load($env, $form_data['edit-path']);
      self::duplicate($env, $source_node, $node_name, $father, $language, true);
    }

    $path = $language == null || $language == \Quanta\Common\Localization::LANGUAGE_NEUTRAL ? 
      $env->nodePath($node_name) : $env->nodePath($node_name) . "/data_{$language}.json";
    $node = new Node($env, $node_name, $father, $language, $path);

    // Setup the after-save redirect.
    if (isset($form_data['redirect'])) {
      $node->setData('redirect', $form_data['redirect']);
    }
    // Perform the requested action.
    switch ($action) {
      case \Quanta\Common\Node::NODE_ACTION_ADD:
      case \Quanta\Common\Node::NODE_ACTION_EDIT:
      case \Quanta\Common\Node::NODE_ACTION_DUPLICATE:
        if ($action == \Quanta\Common\Node::NODE_ACTION_ADD || !$node->exists || (!empty($language) && $node->father->exists)) {
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
          if (isset($form_data['files_count'])) {
            $node->setData('files_count', $form_data['files_count']);
          }
          // TODO complete validation code.
          if (!empty($form_data['edit-thumbnail'])) {
            $node->setThumbnail(Api::normalizeFilePath($form_data['edit-thumbnail']));
          }

          if(isset($form_data['author'])){
            $node->setAuthor($form_data['author']);
          }

          if (isset($form_data['edit-status'])) {
            $node->setStatus($form_data['edit-status']);
          }

          if(isset($form_data['password'])){
            //check if repeated password no equal the real password
            if(isset($form_data['password_rp']) && $form_data['password'] != $form_data['password_rp']){
              new Message($env,
              t('Password fields are not the same.'),
              \Quanta\Common\Message::MESSAGE_WARNING
              );
              $response->errors = Message::burnMessages();
            }
            else{
              $pass = UserFactory::passwordEncrypt($form_data['password']);
              $node->json->password = $pass;
            }  
          }
          
          $vars = array(
            'node' => &$node,
            'data' => $form_data,
            'full_form_data' => $full_form_data,
            'action' => $action,
            'form_validated' => true,
          );
          // Run the node presave hook.
          $env->hook('node_presave', $vars);

          //general form validation
          $validation_status = self::validateFormData($env,$full_form_data);

          //custom form validation
          //to use it you should add hidden input with name "form_validate" and value it is the name of of the form
          //For example: form_validate => profile_form So the hook : <hook_name>_shadow_profile_form_pre_validate
          //and then you can perfom a custom validation also you can use $vars['full_form_data'] to get full form data (name,type,required,length)
          //then change $vars['form_validated'] to false and the errors will be returned (see validateFormData function)
          if (isset($form_data['form_validate']) && !is_array($form_data['form_validate'])) {
            $form_data['form_validate'] = array($form_data['form_validate']);
          }

          if (isset($form_data['form_validate'])) {
            foreach ($form_data['form_validate'] as $form) {
              if (!empty($form)) {
                $env->hook('shadow_' . $form . '_pre_validate', $vars);
              }
            }
          }
          // If the node is validated, proceed with saving it.
          if ($node->validate() && $validation_status && $vars['form_validated']) {
            $node->save();
            // Hook node_add_complete, node_edit_complete, etc.
            $env->hook($action . '_complete', $vars);
            // Check if 'current_url' is set in the form data, if not, default to the father node's name.
            $redirect_url= isset($form_data['current_url']) ? $form_data['current_url'] : '/' . $node->getFather()->getName() . '/';
            // Set the redirect URL in the response. If 'redirect' is not empty in the form data, use it, otherwise, use the calculated $redirect_url.
            $response->redirect = !empty($form_data['redirect']) ? $form_data['redirect'] : $redirect_url;
          }
          else {
            // TODO: make this good.
            $response->shadowErrors = Message::burnMessages(Message::MESSAGE_TYPE_SCREEN,true);
            http_response_code(400);
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
          // Check if 'current_url' is set in the form data, if not, default to the father node's name.
          $redirect_url= isset($form_data['current_url']) ? $form_data['current_url'] : '/' . $node->getFather()->getName() . '/';
          // Set the redirect URL in the response. If 'redirect' is not empty in the form data, use it, otherwise, use the calculated $redirect_url.
          $response->redirect = !empty($form_data['redirect']) ? $form_data['redirect'] : $redirect_url;        }
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
  public static function render(Environment $env, $node_name = NULL, $language = NULL, $tpl = null, $module = null) {
    $node = empty($node_name) ? NodeFactory::current($env) : NodeFactory::load($env, $node_name);
    $tpl = new NodeTemplate($env, $node, $tpl, $module);
    return $tpl->getHtml();
  }

  /**
   * Validate form data
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param $full_form_data
   *   The array of full form data
   *
   * @return boolean
   * 
   */
  public static function validateFormData(Environment $env, $full_form_data) {
    $validation_status = true;
    foreach ($full_form_data as $form_item => $form_item_data) {
      $value = $form_item_data->value;
      if(is_array($value) && count($value) == 1){
        $value = $value[0];
      }
      $length = (!empty($form_item_data->length) ? $form_item_data->length : 50000);
      $required = $form_item_data->required ? true : null;
      $attributes = array('length' => $length, 'type' => $form_item_data->type, 'required' => $required, 'name' => $form_item, 'value' => $value );
      $form_state = null;
      $input_item = \Quanta\Common\FormFactory::createInputItem($env, $attributes, $form_state);
      $input_item->validate();
      if(!$input_item->getValidationStatus()){
        $validation_status = false;
        self::shadowMessage($env,$input_item->getValidationMessage(),$form_item);
      }
    }
    return $validation_status;
  }

  public static function shadowMessage($env,$message,$form_item_name){
    return  new Message($env,
            $message,
            \Quanta\Common\Message::MESSAGE_WARNING,
            \Quanta\Common\Message::MESSAGE_TYPE_SCREEN,
            \Quanta\Common\Message::MESSAGE_NOMODULE,
            $form_item_name
            );
  }

  public static function duplicate($env, $source_node, $new_node_name, $father, $language = \Quanta\Common\Localization::LANGUAGE_NEUTRAL, $subnodes = true, $overrides = array()){
    $new_node = new Node($env, $new_node_name, $father, $language);
    $new_node->json = $source_node->json;
    $new_node->setAuthor($source_node->getAuthor());
    $new_node->setThumbnail($source_node->getThumbnail());
    $new_node->save();
    // Check if the node have multiple language files
    $language_files = glob($source_node->path . '/data_*.json');
    if(count($language_files)){
      foreach ($language_files as $language_file){
        $file_name = basename($language_file);
        $new_file_path = $new_node->path . '/' . $file_name;
        //copy the file
        copy($language_file, $new_file_path);
      }
    }
     // Copy all other files (e.g., images, etc.) except `data*.json`
     $all_files = glob($source_node->path . '/*'); // Get all files in the directory
     if (count($all_files)) {
         foreach ($all_files as $file) {
             $file_name = basename($file);
             // Exclude files that are `data.json` or `data_<language>.json`
             if (!preg_match('/^data(_[a-zA-Z]+)?\.json$/', $file_name)) {
                 $new_file_path = $new_node->path . '/' . $file_name;
                 copy($file, $new_file_path);
             }
         }
     }
    // If subnodes should be cloned
    if ($subnodes) {
      $source_sub_nodes = new \Quanta\Common\DirList($env,  $source_node->name, null,[], 'node');
        foreach ($source_sub_nodes->getItems() as $subnode) {
            // Replace the source node name in subnode with the new node's name
            $new_subnode_name = str_replace($source_node->name, $new_node_name, $subnode->name);
            if (!str_contains($subnode->name, $source_node->name)) { 
              $new_subnode_name = $env->getCandidatePath($new_subnode_name);
            }
            // Fix the father name
            $new_subnode_father = str_replace($source_node->father, $new_node_name, $new_node_name);
            // Recursively clone subnodes
            self::duplicate($env, $subnode, $new_subnode_name, $new_subnode_father, $subnode->language, true);
        }
    }
    // Return the new cloned node
    return $new_node;
  }


}
