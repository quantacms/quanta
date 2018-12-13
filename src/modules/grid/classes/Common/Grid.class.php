<?php
namespace Quanta\Common;

/**
 * Class Grid.
 *
 * This class manages a Quanta grid containing Qtags.
 */
class Grid extends DataContainer {

  /**
   * Check if the browser supports CSS grid.
   *
   * @return boolean
   *   true if browser supports CSS grid.
   */
  public static function checkBrowserSupport() {
    $ua = Api::getBrowser();
    // EDGE: Google Chrome version 58 and up -> OK grid.
    // Android base browser: Google Chrome 4.0 -> NO grid.
    return empty($ua) ? NULL : !( $ua['name'] == "Internet Explorer"
      || ( $ua['name'] == "Google Chrome" && $ua['version'] < 58 )
      || ( $ua['name'] == "Apple Safari" && $ua['version'] < 10.3 ) );
  }
}