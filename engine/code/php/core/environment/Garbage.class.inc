<?php

/**
 * Class Garbage
 * This is a garbage collecting class for removing temp files etc.
 */
class Garbage {

  /** @var Environment $env */
  private $env;

  // Construct the Garbage collector item.
  public function __construct($env) {
    $this->env = $env;
  }

  /**
   * Run garbage collector on all temporary files.
   */
  public function collect() {
    $tmp_dir = $this->env->dir['tmp'] . '/files';
    $scan = $this->env->scanDirectory($tmp_dir, array('type' => DIR_FILES));

    foreach ($scan as $tmp_file) {
      $filepath = $tmp_dir . '/' . $tmp_file;
      $lastmod = filemtime($filepath);
      // The temp file is old? Delete it!
      if ((time() - $lastmod) > 20) {
        // unlink($filepath) or die("can not delete file: " . $filepath);
        // TODO: all commented. What to do?
      }
    }
  }
}
