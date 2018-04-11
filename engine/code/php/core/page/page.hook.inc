<?php
/**
 * Implements hook_body_classes.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function page_body_classes($env, $vars) {
  $vars['page']->addData('body_classes', array('page'));
}

/**
 * Implements hook_boot().
 *
 * React to robots.txt requests.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function page_boot($env, $vars) {
  $env->sysdir('pages', 'pages');
  $env->sysdir('system', '_system');

  // Start robots.txt configuration.
  // TODO: object oriented, using templates, etc. etc.
  if ($env->request[count($env->request) - 1] == 'robots.txt') {
    $robots = new Robots($env);
    $robots->disallow('*', '/search');
    $robots->disallow('*', '/qtags');

    // $robots->disallow('*', '/files*');
    // $robots->disallow('*', '/css*');
    // $robots->disallow('*', '/js*');
    header("Content-Type: text/plain");
    print $robots->render();
    exit();
  }
}


/**
 * Implements hook_setup.
 * Main setup of users.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   Miscellaneous environment / page variables.
 */
function page_setup($env, $vars) {
  // Create the basic roles.
  $basic_pages = array(
    '403' => array('title' => '403 - Forbidden'),
    '404' => array('title' => '404 - File not found'),
  );

  foreach ($basic_pages as $page => $pagedata) {
    if (!is_dir($env->dir['system'] . '/' . $page)) {
      NodeFactory::buildNode($env, $page, '_system', $pagedata);
    }
  }

}

/**
 * Implements hook_doctor().
 * Refresh minified CSS, JS, and and do other operations.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function page_doctor($env, $vars) {
  /** @var Doctor $doctor */
  $doctor = $vars['doctor'];
  
  $css = array();
  $js = array();
  $doctor->op('Generating minified CSS and JS files...');
  // Include JS / CSS files.
  foreach ($env->getIncludes() as $file) {
    if (substr($file['path'], 0, 6) == 'engine') {
      $file['path'] = $env->dir['quanta'] .'/' . $file['path'];
    }

    if ($file['type'] == 'css') {
      $doctor->talk($file['path']);
      $css[] = file_get_contents($file['path']);

    } elseif ($file['type'] == 'js') {
      $doctor->talk($file['path']);
      $js[] = file_get_contents($file['path']);
    }
  }

  $temp_aggregate_path = md5(time() + rand(1,10000000));

  // Create a temporary aggregated css / js file.
  $temp_css_path = $env->dir['tmp_files'] . '/' . $temp_aggregate_path . '.css';
  $temp_js_path = $env->dir['tmp_files'] . '/' . $temp_aggregate_path . '.js';

  $fop = fopen($temp_css_path, 'w+');
  fwrite($fop, implode('
    ', $css));
  fclose($fop);

  $fop = fopen($temp_js_path, 'w+');
  fwrite($fop, implode('
    ', $js));
  fclose($fop);
  
	$doctor->op('Writing minified files in directory: ' . $env->dir['tmp_files']);

  // Minify the CSS and JS.
  $temp_css_path_mini = $env->dir['tmp_files'] . '/css.min.css';
  $temp_js_path_mini = $env->dir['tmp_files'] . '/js.min.js';

  // TODO: is double step necessary?
  $minify_css = minify($env, $temp_css_path);
  $fop_mini = fopen($temp_css_path_mini, 'w+');
  fwrite($fop_mini, implode($minify_css));
  fclose($fop_mini);
  
  // TODO: is double step necessary?
  $minify_js = minify($env, $temp_js_path);
  $fop_mini = fopen($temp_js_path_mini, 'w+');
  fwrite($fop_mini, implode($minify_js));
  fclose($fop_mini);

  unlink($temp_css_path);
  unlink($temp_js_path);

  $doctor->talk('...done!');

}
