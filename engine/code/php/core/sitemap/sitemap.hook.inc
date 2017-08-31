<?php
function sitemap_boot($vars) {
  // TODO: object oriented, using templates, etc. etc.
  if ($vars['env']->getRequestedPath() == 'google_sitemap') {
      header("Content-type: text/xml");

      print "<?xml version=\"1.0\" encoding=\"utf-8\" ?>" . "\n";
    echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    $site_pages = $vars['env']->scanDirectoryDeep($vars['env']->dir['docroot'], '', array(), array(
      'exclude_dirs' => DIR_INACTIVE,
      'symlinks' => FALSE,
      DIR_ALL,
      'level' => 'tree'
    ));

    $already_done = array();
    foreach ($site_pages as $item) {
        $node = NodeFactory::load($vars['env'], $item['name']);
        $modtime = (file_exists($item['path'] . '/data.json') ? filemtime($item['path'] . '/data.json') : filemtime($item['path']));

        if (!($node->isPublished()) || isset($already_done[$item['name']])) {
          continue;
        }
        $already_done[$item['name']] = TRUE;
        $priority = ($item['name'] == '') ? 1 : 0.7;
        $freq = ($item['name'] == '' ? 'daily' : 'weekly');

      echo "<url>\n";
      echo "<loc>" . $vars['env']->getBaseUrl() . "/" . $item['name'] . "</loc>";
      echo "<lastmod>" . date('Y-m-d', $modtime) . "</lastmod>\n";
      echo "<changefreq>" . $freq . "</changefreq>\n";
      echo "<priority>" . $priority . "</priority>\n";
      echo "</url>\n";
    }
    print "</urlset>\n";

    exit();
  }
}

