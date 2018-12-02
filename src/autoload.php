<?php
/**
 * Quanta's autoloader.
 *
 * We are implementing a custom autoloader, as PSR-4 doesn't seem to fit our needs.
 *
 * Ideas and contributions welcome at https://www.github.com/quantacms/quanta
 */

/**
 * Iterate all the modules and load them.
 */
foreach (new DirectoryIterator('modules') as $module) {
  if (!($module->isDot())) {
    print $module->getFilename();
    require_once('modules/' . $module . '/' . $module . '.module');
  }
}

print_r($modules);
die();

// Include the Environment module.
require_once('modules/environment/environment.module');

// Include the Cache module.
require_once('modules/cache/cache.module');

/**
 * Load core modules.
 */
  $core_modules = $this->scanDirectory($this->dir('modules_core') , array('type' => DIR_MODULES));
  $this->setModules('core', $core_modules);

