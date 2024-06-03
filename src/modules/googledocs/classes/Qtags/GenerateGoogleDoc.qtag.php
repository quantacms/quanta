<?php
namespace Quanta\Qtags;

/**
 * Class FormItemFile
 * This class represents a Form Item of type File upload
 */
class GenerateGoogleDoc extends Qtag {

  /**
   * Renders.
   * @return mixed
   */
  function render() {
    $generate_path = \Quanta\Common\GoogleDocs::GENERATE_GOOGLE_DOC_PATH;
    //TODO: I will fix this
    return "[LINK|title=Generate Doc:{$generate_path}]";
}

}
