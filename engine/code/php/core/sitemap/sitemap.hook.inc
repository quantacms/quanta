<?php
function sitemap_init($vars) {
  // TODO: object oriented, using templates, etc. etc.
  if ($vars['env']->getRequestedPath() == 'google_sitemap') {
      header("Content-type: text/xml");

      print '<?xml version="1.0" encoding="utf-8">
    ';
    print '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    ';
    $site_pages = $vars['env']->scanDirectoryDeep($vars['env']->dir['docroot'], '', array(), array(
      'exclude_dirs' => DIR_INACTIVE,
      'symlinks' => FALSE,
      DIR_ALL,
      'level' => 'tree'
    ));

    foreach ($site_pages as $item) {
        $modtime = (file_exists($item['path'] . '/data.json') ? filemtime($item['path'] . '/data.json') : filemtime($item['path']));

        $priority = ($item['name'] == '') ? 1 : 0.7;
        $freq = ($item['name'] == '' ? 'daily' : 'weekly');

      print '<url>
';
      print '<loc>' . $vars['env']->getBaseUrl() . '/' . $item['name'] . '</loc>
';
      print '<lastmod>' . date('Y-m-d', $modtime) . '</lastmod>';
      print '<changefreq>' . $freq . '</changefreq>
';
      print '<priority>' . $priority . '</priority>
';
      print '</url>
';
    }

    print '</urlset>
';

    exit();
  }
}