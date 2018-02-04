<?php
/**
 * Class Page
 * This class represents a Page (corrisponding to a rendered html page).
 */
class Robots extends DataContainer {
  public $html;
  public $disallow = array();
  public $file;

  /**
   * @param $env Environment
   * @internal param $filename
   * @internal param null $name
   * @internal param null $content
   */
  public function __construct(&$env) {

  }

  /**
   * @param $user_agent
   * @param $path
   */
  public function disallow($user_agent, $path) {
    $this->disallow[$user_agent][] = $path;
  }

  /**
   * @return string
   */
  public function render() {
    $render = '';
    foreach ($this->disallow as $user_agent => $paths) {
      $render .= 'User-agent: ' . $user_agent . "\n";
      foreach ($paths as $path) {
        $render .= 'Disallow: ' . $path . "\n";
      }
    }
    return $render;
  }
}
