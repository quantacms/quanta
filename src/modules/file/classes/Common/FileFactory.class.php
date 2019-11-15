<?php
namespace Quanta\Common;
/**
 * Class FileFactory
 *
 * This Factory class contains static methods for loading, manipulating, saving
 * and deleting Nodes. Also serves as a rendering tool for loading and
 * applying Node Templates.
 *
 */
class FileFactory {
  /**
   * Check if the current request is for a file.
   */
  public static function checkFile(Environment $env) {
    // Support for letsencript https certificates.
    if (!empty($env->request_path) && $env->request_path == 'acme-challenge') {
      readfile('/' . trim($env->dir['docroot'] . implode('/', $env->request), '/'));
      die();
    }
    // TODO: redo the whole shit.
    if (strpos($env->request[count($env->request) - 1], '.') > 0) {
      $filename = $env->request[count($env->request) - 1];
      $nodepath = Cache::getStoredNodePath($env, $env->request[count($env->request) - 2]);
      $file = $nodepath . '/' . urldecode($filename);
      if (is_file($file)) {
        header('Content-Type: ' . mime_content_type($file));
        $mods = array_flip(apache_get_modules());
        if (isset($mods['mod_xsendfile'])) {
          // TODO: support for xsendfile.
          // readfile($file);
        }
        else {
          //TODO : slow, insecure...
           readfile($file);
        }
        exit();
      }
      elseif ($filename == 'favicon.ico') {
        die("No favicon available.");
      }
    }
  }

}
