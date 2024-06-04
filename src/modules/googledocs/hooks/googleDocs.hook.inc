<?php
namespace Quanta\Common;


/**
 * @param Environment $env
 * @param $vars
 */
function googleDocs_boot($env, $vars) {
    try {
      $google_doc_object = new \Quanta\Common\GoogleDocs($env);

      if($env->request_path == \Quanta\Common\GoogleDocs::GENERATE_GOOGLE_DOC_PATH){
          $google_doc_object->checkSession();
          $doc = $google_doc_object->createDocument($env,'');
          $vars['doc'] = $doc;
          //TODO: show a success message
          $env->hook('google_doc_generated',$vars);
          \Quanta\Common\Api::redirect('/');
         
      }
      else if($env->request_path == \Quanta\Common\GoogleDocs::GOOGLE_AUTH_CALLBACK_PATH){

        $google_doc_object->setAccessToken($_GET['code']);
        \Quanta\Common\Api::redirect('/');
      }
    } catch (\Throwable $th) {
       print_r($th);
       die();   
    }


}