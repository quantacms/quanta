<?php
namespace Quanta\Common;


/**
 * @param Environment $env
 * @param $vars
 */
function googledocs_boot($env, $vars) {
    try {
      $google_doc_object = new \Quanta\Common\GoogleDocs($env);

      if($env->request_path == \Quanta\Common\GoogleDocs::GENERATE_GOOGLE_DOC_PATH){
          $registered = $google_doc_object->checkSession();
          if (!$registered) {
            $google_doc_object->createAuthUrl();
            $_SESSION['text_body'] = $_GET['text'];
          }
          $env->hook('google_doc_before_generate',$vars);
          $doc = $google_doc_object->createDocument($env,$_GET['text']);
          $vars['doc'] = $doc;
          //TODO: show a success message
          $env->hook('google_doc_generated',$vars);
         
      }
      else if($env->request_path == \Quanta\Common\GoogleDocs::GOOGLE_AUTH_CALLBACK_PATH){
        $google_doc_object->setAccessToken($_GET['code']);
        // print_r('aa');
        // die();
        \Quanta\Common\Api::redirect('/');
      }
      else if($env->request_path == \Quanta\Common\GoogleDocs::READ_GOOGLE_DOC_PATH){
      
        $registered = $google_doc_object->checkSession();
        if (!$registered) {
          $google_doc_object->createAuthUrl();
        }
        $text_doc = $google_doc_object->readDocument($_GET['doc-id']);
        $vars['text_doc'] = $text_doc;
        $env->hook('google_doc_readed',$vars);

      }
    } catch (\Throwable $th) {
       print_r($th);
       die();   
    }


}