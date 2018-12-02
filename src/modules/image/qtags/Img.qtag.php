<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;
use Quanta\Common\Image;
use Quanta\Common\Api;
/**
 * Renders an image.
 */
class Img extends Qtag {
  public $node = NULL;
  public $src;
  public $alt;
  public $img_class = array();
  protected $manipulate = FALSE;

  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    if (isset($this->attributes['node'])) {
      $this->node = $this->attributes['node'];
    }
    $node = NodeFactory::loadOrCurrent($this->env, $this->node);
    // Load the image.
    $image = new Image($this->env, $this->getTarget(), $node);
    $image->loadAttributes($this->attributes);

    // Setup fallback image.
    $fallback = isset($this->attributes['fallback']) ? $this->attributes['fallback'] : NULL;

    // Setup alt for the image.
    if (isset($this->attributes['alt'])) {
      $this->alt = $this->attributes['alt'];
    }
    else {
      $split_target = explode('.', $this->getTarget());
      $this->alt = str_replace('-', ' ', Api::string_normalize($split_target[0]));
    }

    // Load classes.
    $this->img_class = array('image');
    if (!empty($this->attributes['img_class'])) {
      $this->img_class += explode(' ', $this->attributes['img_class']);
    }

    // There is a request for editing the Image "on the fly" (i.e. scale or resize).
    // Proceed with creating an rendering the new image.
    if ($this->manipulate) {
      // Setup compression level (JPEG / PNG).
      $compression = isset($this->attributes['compression']) ? $this->attributes['compression'] : 60;

      // Setup operations to run on the image.
      $op = isset($this->attributes['operation']) ? $this->attributes['operation'] : 'scale';

      // Build array of variables.
      $vars = array(
        'w_max' => $image->width,
        'h_max' => $image->height,
        'image_action' => $op,
        'compression' => $compression,
        'fallback' => $fallback,
      );
      // Generate the thumbnail of the requested image.
      $newthumbfile = $image->generateThumbnail($this->env, $vars);

      // TODO: stupid way to get to the tmp folder...
      $this->src = '/thumbs/' . $newthumbfile;

    }
    else {
      $this->src = $this->getTarget();
    }
    // Generate the image's url.
    if (isset($this->attributes['url'])) {
      $this->html = $this->src;
    }
    else {
      // Generate the HTML of the thumbnail.
      $this->html = '<img width="' . $image->width . '" height="' . $image->height . '" alt="' . $this->alt . '" class="' . implode(' ', $this->img_class) .  '" src="' . $this->src . '" />';
    }

    return $this->html;
  }
}
