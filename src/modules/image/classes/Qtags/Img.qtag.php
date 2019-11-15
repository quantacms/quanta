<?php
namespace Quanta\Qtags;

/**
 * Renders an image.
 */
class Img extends HtmlTag {
  public $node = NULL;
  public $src;
  public $alt;
  public $html_params = array('class' => 'image');
  public $html_tag = 'img';
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
    $node = \Quanta\Common\NodeFactory::loadOrCurrent($this->env, $this->node);
    // Load the Image object.
    $image = new \Quanta\Common\Image($this->env, $this->getTarget(), $node);
    $image->loadAttributes($this->attributes);

    // Setup a fallback image if the image is not found / not valid.
    $fallback = isset($this->attributes['fallback']) ? $this->attributes['fallback'] : NULL;

    // Setup alt for the image.
    if (isset($this->attributes['alt'])) {
      $this->alt = $this->attributes['alt'];
    }
    else {
      $split_target = explode('.', $this->getTarget());
      $this->alt = str_replace('-', ' ', \Quanta\Common\Api::string_normalize($split_target[0]));
    }

    // Load classes.
    if (!empty($this->attributes['img_class'])) {
      $this->html_params['class'] .= $this->attributes['img_class'];
    }
    // When there is a request for editing the Image "on the fly" (i.e. scale or resize)...
    // ...Proceed with creating and rendering the new manipulated image.
    if ($this->manipulate) {
      // Setup compression level (JPEG / PNG).
      $compression = isset($this->attributes['compression']) ? $this->attributes['compression'] : 60;

      // Setup operations to run on the image.
      $op = isset($this->attributes['operation']) ? $this->attributes['operation'] : 'scale';

      // Build array of variables.
      $vars = array(
        'w_max' => $image->width,
        'h_max' => $image->height,
        'operation' => $op,
        'compression' => $compression,
        'fallback' => $fallback,
      );
      // Generate the thumbnail of the requested image.
      $newthumbfile = $image->generateThumbnail($this->env, $vars);

      // TODO: stupid way to get to the tmp thumbs folder...
      $this->src = '/thumbs/' . $newthumbfile;

      // TODO: redundant with Image.class.php?.
      if (!empty($this->getAttribute('autosize'))) {
        $get_size = getimagesize($this->env->dir['thumbs'] . '/' . $newthumbfile);
        $image->width = $get_size[0];
        $image->height = $get_size[1];
      }

    }
    else {
      $this->src = $image->external ? $image->getRelativePath() : $this->getTarget();
    }
    // Generate the image's url.
    if (isset($this->attributes['url'])) {
      $rendered = $this->src;
    }
    else {

      // Generate the HTML of the image.
      $this->html_params['alt'] = $this->alt;
      $this->html_params['src'] = $this->src;
      $this->html_params['width'] = $image->width;
      $this->html_params['height'] = $image->height;
      $this->html_self_close = TRUE;
      $rendered = parent::render();
    }

    return $rendered;
  }
}
