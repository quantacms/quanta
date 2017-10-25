<?php

/**
 * Class NodeFactory
 * This factory class is used for loading nodes, etc.
 */
class NodeFactory {

  /**
   * Load a node, by checking access and running hooks.
   * @param $env
   * @param $node
   */
  public static function load(Environment $env, $node, $tpl = NULL, $language = NULL) {
    if (empty($language)) {
      $language = $env->getLanguage();
    }
    $node = new Node($env, $node, NULL, $tpl, $language);
    $env->hook('node_open', array('node' => &$node), NULL, $tpl);
    return $node;
  }

  /**
   * Create a "Forbidden" empty node.
   * @param $env
   * @param $node
   */
  public static function buildForbiddenNode($env) {
    $node = new Node($env, NULL);
    $node->forbidden = TRUE;
    $node->exists = TRUE;
    $node->setBody('FORBIDDEN');
    return $node;
  }


  /**
   * Create an empty node.
   * @param $env
   * @param $node
   */
  public static function buildEmptyNode($env, $father) {
    $node = new Node($env, NODE_NEW, $father);
    return $node;
  }


  /**
   * Create a node with basic values.
   * @param $env
   * @param $node
   */
  public static function buildNode($env, $name, $father, $vars = array()) {
    $node = new Node($env, NODE_NEW, $father);
    $node->setName($name);

    foreach($vars as $field_name => $field_value) {
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
    $node->save();

    return $node;
  }

  /**
   * Gets the current viewed node.
   * @return bool
   */
  public static function current(Environment $env) {
    $tpl = isset($_REQUEST['tpl']) ? $_REQUEST['tpl'] : NULL;
    if ($env->getContext() == NODE_ACTION_ADD) {
      $node = NodeFactory::buildEmptyNode($env, $env->getRequestedPath());
    } else {
      $node = NodeFactory::load($env, $env->getRequestedPath(), $tpl);
    }
    return $node;
  }

  /**
   * Request to perform an action on the node. Check permissions and
   * execute the action accordingly.
   * @param $env Environment
   * @param $action string
   * @return string
   */
  public static function requestAction(Environment $env, $action, $nodedata) {

    // TODO: this shit is needed with new approach.
    foreach ($nodedata as $k => $v) {
      if (is_array($nodedata[$k]) && (count($nodedata[$k]) == 1)) {
        $nodedata[$k] = array_pop($v);
      }
    }

    $response = new StdClass();

    // TODO: circular dependency to user.
    $user = UserFactory::current($env);

    // TODO: refactor this stuff.
    // When user didn't enter a path...
    if (trim($nodedata['edit-path']) == '') {
      $path = $env->getCandidatePath($nodedata['edit-title']);
    }
    else {
      $path = $nodedata['edit-path'];
    }

		$father = ($action == NODE_ACTION_ADD) ? $nodedata['edit-father'] : NULL;
    $node = new Node($env, $path, $father);

    switch ($action) {
      case NODE_ACTION_ADD:
      case NODE_ACTION_EDIT:
        if ($action == NODE_ACTION_ADD) {
					$node->realpath = $node->father->realpath . '/' . $path;
          $node->setAuthor($user->getName());
        }
        // TODO complete validation code.
        if (isset($nodedata['edit-thumbnail'])) {
          $node->setThumbnail($nodedata['edit-thumbnail']);
        }

        $datetime = strtotime($nodedata['edit-date'] . ' ' . $nodedata['edit-time']);
        // TODO: why $has_access is unused?
        $has_access = ($user->checkAccess(($action == NODE_ACTION_ADD) ? $node->father : $node, $action));
        if (TRUE) {
          $node->setTitle($nodedata['edit-title']);
          $node->setBody($nodedata['edit-content']);
          $node->setAuthor($nodedata['edit-author']);
          $node->setTeaser($nodedata['edit-teaser']);
          $node->setContent('<h1>' . $node->getTitle() . '</h1>' . $node->getBody());
          $node->setTimestamp($datetime > 0 ? $datetime : time());
          $node->tmp_files_dir = $nodedata['tmp_files_dir'];
          if (isset($nodedata['redirect'])){
            $node->setData('redirect',$nodedata['redirect']);
          }

          $env->hook('node_presave', array(
            'node' => &$node,
            'data' => $nodedata,
            'action' => $action,
          ));

          if ($node->validate()) {
            $node->save();

            $env->hook('node_save', array(
              'node' => &$node,
              'data' => $nodedata
            ));
            
            $env->hook('node_post_save', array(
              'node' => &$node,
              'data' => $nodedata
            ));

            $response->redirect = !empty($node->getData('redirect')) ? $node->getData('redirect') : ('/' . $node->getName() . '/');
          }
          else {
            // TODO: make this good.
            $response->errors = Message::burnMessages();
          }
        }
        else {
          $response->redirect = '/403';
        }

        break;

      case NODE_ACTION_DELETE:
        $has_access = ($user->checkAccess($node, NODE_ACTION_DELETE));
        if ($has_access) {
          $node->delete();
          new Message($node->env, $node->getName() . ' was deleted correctly');
          $response->redirect = !empty($node->getData('redirect')) ? $node->getData('redirect') : ('/' . $node->getFather()->getName() . '/');          
        }
        break;
    }

    $response_json = json_encode($response);

    return $response_json;
  }

}
