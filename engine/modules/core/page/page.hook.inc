<?php

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function page_load_includes($env, $vars) {
  $module_path = $env->getModulePath('page');
  $env->addInclude($module_path . '/css/page.css');
}

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
 * Implements hook_doctor_setup.
 * Main setup of users.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   Miscellaneous environment / page variables.
 */
function page_doctor_setup($env, $vars) {
  /** @var Doctor $doctor */
  $doctor = $vars['doctor'];
  $doctor->op('Installing basic pages!');

  // Create the index.html file.
  $doctor->op('Looking for index.html...');
  if (!is_file($env->dir['docroot'] . '/index.html')) {
    $doctor->talk('Not Found');
    // TODO: what to use as default for newly installed sites? Create templates folder.
    $template_folder = $env->dir['quanta'] . '/_examples/_example1/*';
    $doctor->execute('cp -R ' . $template_folder . ' ' . $doctor->env->dir['docroot']);
    $doctor->ok('Imported from ' . $template_folder);
  }
  else {
    $doctor->ok('Found');
  }

  // Create the basic roles.
  $basic_pages = array(
    '403' => array(
      'title' => '403 - Forbidden',
      'father' => '_system',
    ),
    '404' => array(
      'title' => '404 - File not found',
      'father' => '_system',
    ),
    'home' => array(
      'title' => 'Your Homepage',
      'father' => 'pages',
      'body' => 'Welcome in your new QUANTA Homepage. That\'s where everything begins...',
    ),
  );

  foreach ($basic_pages as $page => $pagedata) {
    $check_exists = NodeFactory::load($env, $page);
    if (!$check_exists->exists) {
      NodeFactory::buildNode($env, $page, $pagedata['father'], $pagedata);
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
  
  $minify_js = minify($env, $temp_js_path);
  $fop_mini = fopen($temp_js_path_mini, 'w+');
  fwrite($fop_mini, implode($minify_js));
  fclose($fop_mini);

  unlink($temp_css_path);
  unlink($temp_js_path);

  $doctor->talk('...done!');

}
