<?php
namespace Quanta\Common;


/**
 * @param Environment $env
 * @param $vars
 */
function googledocs_boot($env, $vars) {
    $allowed_paths = array('generate-google-doc', 'google-auth-callback', 'read-google-doc');
    if(!in_array($env->request_path,$allowed_paths)){
      return ;
    }
    
    $google_doc_object = new \Quanta\Common\GoogleDocs($env);
    if($env->request_path == 'generate-google-doc'){
        $registered = $google_doc_object->checkSession();
        if (!$registered) {
          $google_doc_object->createAuthUrl($env);
        }
        $env->hook('google_doc_before_generate',$vars);
        $doc = $google_doc_object->createDocument($env,$_GET['node_name'],$_GET['key'],$_GET['file-title']);
        $vars['doc'] = $doc;
        //TODO: show a success message
        $env->hook('google_doc_generated',$vars);
        
    }
    else if($env->request_path == 'google-auth-callback'){
      $google_doc_object->setAccessToken($_GET['code']);
      $redirect_url = isset($_SESSION['redirect_afte_auth']) ? $_SESSION['redirect_afte_auth'] : '/';
      \Quanta\Common\Api::redirect($redirect_url);
    }
    else if($env->request_path == 'read-google-doc'){
    
      $registered = $google_doc_object->checkSession();
      if (!$registered) {
        $google_doc_object->createAuthUrl($env);
      }
      $text_doc = $google_doc_object->readDocument($_GET['doc-id']);
      $vars['text_doc'] = $text_doc;
      $env->hook('google_doc_readed',$vars);

    }
}