<?php
/**
 * Wiki Hook for STATS.
 * @param $userfull
 * @param $var
 * @param $string
 * @param $env
 * @return string
 */
function wiki_STATS($userfull, $var, $string, $env) {
  $stats = array();
  $stats[] = 'Node loaded: ' . $env->getData(STATS_NODE_LOADED);
  $stats[] = 'Node built: ' . $env->getData(STATS_NODE_BUILT);
  $stats[] = 'Node built from cache: ' . $env->getData(STATS_NODE_LOADED_CACHE);
  $ttime = $env->getData(STATS_PAGE_COMPLETE_TIME) - $env->getData(STATS_PAGE_BOOT_TIME);
  $stats[] = 'Total time: ' . $ttime . 'ms';
  if ($env->getData(STATS_NODE_BUILT) > 0) {
    $stats[] = 'Node build time: ' .  doubleval($ttime / $env->getData(STATS_NODE_BUILT)) . 'ms';
  }
  $stats[] = 'Node list: ' .  implode(' ', $env->getData(STATS_NODE_LIST));
  $stats[] = 'Loaded tags (only first level - to fix): ' .  $env->getData(STATS_WIKITAG_LOADED);

  $string ='<div id="stats" style="border: 1px solid black; padding: 8px; background: #eee;">' . implode('<br>', $stats) . '</div>';
  return $string;
}

function wiki_GOOGLE_ANALYTICS($tagfull, $var, $string, $env) {
  return "<script>
  (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
  (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
  m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
  })(window,document,'script','https://www.google-analytics.com/analytics.js','ga');

  ga('create', '". $var . "', 'auto');
  ga('send', 'pageview');

</script>";
}