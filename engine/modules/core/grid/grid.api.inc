<?php 

/**
 * Check if the browser supports CSS grid.
 *
 * @return boolean
 *   true if browser supports CSS grid.
 */
function supportsGrid() {
  $ua = getBrowser();
  /*
  $yourbrowser = "Your browser: " . $ua['name'] .
  "<br>" . $ua['version'] . " on " .$ua['platform'] . 
  "<br><br>reports: <br>" . $ua['userAgent'];
  print $yourbrowser;
  */

  // EDGE: Google Chrome version 58 and up -> OK grid.
  // Android base browser: Google Chrome 4.0 -> NO grid.
  return !( $ua['name'] == "Internet Explorer"
    || ( $ua['name'] == "Google Chrome" && $ua['version'] < 58 )
    || ( $ua['name'] == "Apple Safari" && $ua['version'] < 10.3 ) );
}