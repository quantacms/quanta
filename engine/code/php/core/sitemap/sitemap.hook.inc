<?php
function sitemap_init($vars) {
  // TODO: object oriented, using templates, etc. etc.
  if ($vars['env']->getRequestedPath() == 'google_sitemap') {
    print '<?xml version="1.0" encoding="utf-8">
    ';
    print '<urlset xmlns="' . $_SERVER['HTTP_HOST'] . '">
    ';
    $site_pages = $vars['env']->scanDirectoryDeep($vars['env']->dir['docroot'], '', array(), array(
      'exclude_dirs' => DIR_INACTIVE,
      'symlinks' => FALSE,
      DIR_ALL,
      'level' => 'tree'
    ));


    foreach ($site_pages as $page) {
      print '<url>
';
      print '<loc>' . $_SERVER['HTTP_HOST'] . '/</loc>
';
      print '<lastmod>' . filemtime($page . '/data.json') . '</lastmod>
';
      print '<changefreq>' . ('weekly') . '</changefreq>
';
      print '<priority>1</priority>
';
      print '</url>
';
    }

    print '</urlset>
';

    exit();
  }
}