<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;

/**
 * Renders an image.
 */
class Thumbnail extends ImgThumb {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $this->setAttribute('node', $node->getName());
    $thumbnail = $node->getThumbnail();
    $fallback = $this->getAttribute('fallback');
    $target = $thumbnail;
    // Check if the thumbnail is empty and a fallback value is provided
    if (! $thumbnail && !empty($fallback)) {
      // If the fallback is set to 'first_image', attempt to find the first image file
      if ($fallback === 'first_image') {
        // Set attributes to filter for images only
        $this->attributes['file_types'] = 'image';
        $this->attributes['clean'] = true;

        // Get the list of files associated with the node, filtered by the specified attributes
        $filelist = new \Quanta\Common\FileList($this->env, $node->getName(), null, $this->attributes, 'list');

        // Retrieve the filtered list of files
        $files = $filelist->getItems();

        // Loop through the files to find the first one with type 'image'
        foreach ($files as $file) {
          if ($file->type === 'image') {  // Ensure the file is an image
            $target = $file->getName();  // Set the target to the name of the first image found
            break;  // Stop searching after finding the first image
          }
        }
      } else {
        // If the fallback is not 'first_image', use the provided fallback value as the target
        $target = $fallback;
      }
    }
    // Set the target using the determined value
    $this->setTarget($target);
    $html = parent::render();
    if (empty($this->getAttribute('link')) || $this->getAttribute('link') != 'false') {
      $link = new Link($this->env, $this->getAttributes(), $node->getName());
      $link->destination = '/' . (!empty($this->getAttribute('href')) ? $this->getAttribute('href') :  $node->getName());
      $link->setHtmlBody($html);
      $html = $link->render();
    }
    return $html;
  }
}
