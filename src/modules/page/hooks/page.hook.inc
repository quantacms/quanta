<?php
namespace Quanta\Common;

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
  $env->addInclude($module_path . '/assets/css/page.css');
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
    $robots->disallow('*', '/search/');
    $robots->disallow('*', '/qtag/');
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

    // Choose an installation profile for Quanta.
    $profiles = $env->scanDirectory($env->dir['profiles'], array('type' => DIR_DIRS));
    sort($profiles);
    $profiles_choice = array();
    $profile_chosen = NULL;
    // List available installation profiles.
    foreach ($profiles as $k => $profile) {
      $profile_path = $env->dir['profiles'] . '/' . $profile . '/_profile_info';
      $profile_node = NodeFactory::loadFromRealPath($env, $profile_path);
      $profiles_choice[] = $k . ') ' . $profile_node->getTitle() . ', ' . $profile_node->getTeaser();
    }

    if (!empty($doctor->getData('profile'))) {
      $profiles_rev = array_flip($profiles);
      $choice = $doctor->getData('profile');
      if (isset($profiles_rev[$choice])) {
        $doctor->ok('Pre-selecting profile: ' . $choice);
      }
      else {
        $doctor->stop('Invalid profile pre-selected: ' . $choice . ". Valid profiles are: \n" . implode("\n", $profiles) . "\n Aborting");
      }
      $profile_chosen = $choice;
    }
    while (!isset($profile_chosen)) {
      if (isset($choice)) {
        $doctor->ko(t('Invalid choice.'));
      }
      $choice = $doctor->ask(t("Please choose an install profile from the following:\n%profiles", array("%profiles" => implode("\n", $profiles_choice))));
      if (isset($profiles[$choice])) {
        $profile_chosen = $profiles[$choice];
      }
    }
    $doctor->op(t('Installing profile: %profile', array('%profile' => $profile_chosen)));

    $template_folder = $env->dir['profiles'] . '/' . $profile_chosen . '/*';

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
      'body' => '<p>Whoops. Apparently you don\'t have the permission to access the requested page.<\/p><p><a href=\"\/\">Back to Home<\/a><\/p>',
    ),
    '404' => array(
      'title' => '404 - File not found',
      'father' => '_system',
      'body' => '<p>Whoops. Apparently the content you were looking for, is not here!<\/p><p><a href=\"\/\">Back to Home<\/a><\/p>',
    ),
    'home' => array(
      'title' => 'Your Homepage',
      'father' => 'pages',
      'body' => '<p>Welcome in your new QUANTA Homepage. That\'s where everything begins...</p>',
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
 * Implements hook_init().
 * Construct and render a HTML page.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function page_init($env, $vars) {
  // Start page's standard index.html.
  $page = new \Quanta\Common\Page($env);
  $vars = array('page' => &$page);
  $env->setData('page', $vars['page']);

  // Check if there is an AJAX request in progress. TODO: move in ajax module.
  if (!isset($_REQUEST['ajax'])) {
    $env->hook('load_includes', $vars);
    $page->loadIncludes();
  }
  // Build the page's HTML code.
  $page->buildHTML();
  // Render the page.
  print $page->render();
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
  $minify_css = Api::minify($env, $temp_css_path);
  $fop_mini = fopen($temp_css_path_mini, 'w+');
  fwrite($fop_mini, implode('', $minify_css));
  fclose($fop_mini);
  
  $minify_js = Api::minify($env, $temp_js_path);
  $fop_mini = fopen($temp_js_path_mini, 'w+');
  fwrite($fop_mini, implode('', $minify_js));
  fclose($fop_mini);

  unlink($temp_css_path);
  unlink($temp_js_path);

  $doctor->talk('...done!');

}
