<?php
namespace Quanta\Common;

/**
 * Implements hook_boot().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function sitemap_boot(Environment $env, array $vars) {
  // TODO: refactor with object oriented approach, using templates, etc. etc.
  if ($env->getRequestedPath() == 'google_sitemap') {
    header("Content-type: text/xml");

    print "<?xml version=\"1.0\" encoding=\"utf-8\" ?>" . "\n";
    echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    $site_pages = $env->scanDirectoryDeep($env->dir['docroot'], '', array(), array(
      'exclude_dirs' => DIR_INACTIVE,
      'symlinks' => FALSE,
      DIR_ALL,
      'level' => 'tree'
    ));

    $already_done = array();
    foreach ($site_pages as $item) {
      $node = NodeFactory::load($env, $item['name']);
      $modtime = (file_exists($item['path'] . '/data.json') ? filemtime($item['path'] . '/data.json') : filemtime($item['path']));

      if (!($node->isPublished()) || isset($already_done[$item['name']]) || $node->getAttributeJSON('sitemap_exclude')) {
        continue;
      }
      $already_done[$item['name']] = TRUE;
      $priority = ($item['name'] == '') ? 1 : 0.7;
      $freq = ($item['name'] == '' ? 'daily' : 'weekly');

      echo "<url>\n";
      echo "<loc>" . $env->getBaseUrl() . "/" . $item['name'] . "</loc>";
      echo "<lastmod>" . date('Y-m-d', $modtime) . "</lastmod>\n";
      echo "<changefreq>" . $freq . "</changefreq>\n";
      echo "<priority>" . $priority . "</priority>\n";
      echo "</url>\n";
    }
    print "</urlset>\n";

    exit();
  }
}
