<?php
/**
 * Implements hook_body_classes.
 */
function page_body_classes($vars) {
  $vars['page']->addData('body_classes', array('page'));
}

/**
 * React to robots.txt request.
 * @param $vars
 */
function page_boot($vars) {
  // Start robots.txt configuration.
  // TODO: object oriented, using templates, etc. etc.
  if ($vars['env']->request[count($vars['env']->request) - 1] == 'robots.txt') {
    $robots = new Robots($vars['env']);
    $robots->disallow('*', '/search');
    // $robots->disallow('*', '/files*');
    // $robots->disallow('*', '/css*');
    // $robots->disallow('*', '/js*');
    header("Content-Type: text/plain");
    print $robots->render();
    exit();
  }
}

/**
 * Refresh CSS JS and do other operations.
 * @param $vars
 */
/**
 * Main setup of languages.
 * @param $vars
 */
function page_setup($vars) {
  $css = array();
  $js = array();

  Doctor::talk('Generating minified CSS and JS files...');
  // Include JS / CSS files.
  foreach ($vars['env']->getIncludes() as $file) {
    if (substr($file['path'], 0, 6) == 'engine') {
      $file['path'] = $_SERVER['DOCUMENT_ROOT'] .'/' . $file['path'];
    }

    if ($file['type'] == 'css') {
      $css[] = file_get_contents($file['path']);

    } elseif ($file['type'] == 'js') {
      $js[] = file_get_contents($file['path']);
    }
  }

  $temp_aggregate_path = md5(time() + rand(1,10000000));

  $temp_css_path = $vars['env']->dir['tmp_files'] . '/' . $temp_aggregate_path . '.css';
  $temp_js_path = $vars['env']->dir['tmp_files'] . '/' . $temp_aggregate_path . '.js';

  $fop = fopen($temp_css_path, 'w+');
  fwrite($fop, implode('
    ', $css));
  fclose($fop);

  $fop = fopen($temp_js_path, 'w+');
  fwrite($fop, implode('
    ', $js));
  fclose($fop);

  // MINIFY

  $temp_css_path_mini = $vars['env']->dir['tmp_files'] . '/css.min.css';
  $temp_js_path_mini = $vars['env']->dir['tmp_files'] . '/js.min.js';
  // TODO: is double step necessary?
  $minify_css = minify($vars['env'], $temp_css_path);
  $fop_mini = fopen($temp_css_path_mini, 'w+');
  fwrite($fop_mini, implode($minify_css));
  fclose($fop_mini);

  // TODO: is double step necessary?
  $minify_js = minify($vars['env'], $temp_js_path);
  $fop_mini = fopen($temp_js_path_mini, 'w+');
  fwrite($fop_mini, implode($minify_js));
  fclose($fop_mini);

  unlink($temp_css_path);
  unlink($temp_js_path);

  Doctor::talk('...done!');

}