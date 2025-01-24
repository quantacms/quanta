<?php

namespace Quanta\Common;

/**
 * This class allows creation, manipulation and transformation
 * of Images.
 * It extends the basic FileObject class, to which it adds image editing functions.
 *
 */
class Image extends FileObject {
   const IMAGE_RENDER_FULL = 'image_full';

  /** @var int $width */
  public $width = '';
  /** @var int $height */
  public $height = '';
  /** @var array $css */
  public $css = array();
  /** @var array $class */
  public $class = array();
  /** @var string $title */
  public $title = NULL;

  /**
   * Load the image's  attributes.
   *
   * @param array $attributes
   */
  public function loadAttributes($attributes) {
    foreach ($attributes as $attname => $attribute) {
      // Check the image size as input by the user.
      if (preg_match_all('/[0-9|auto]x[0-9|auto]/', $attname, $matches)) {
        $size = explode('x', $attname);
        $this->width = $size[0];
        $this->height = $size[1];
      }

      else switch (strtolower($attname)) {
        case 'class':
          $this->class = explode(',', $attribute);
          break;
        case 'float':
          $this->css[] = 'float:' . $attribute;
          break;
        case 'title':
          $this->setTitle($attribute);
          break;
        case 'w':
          $this->width = $attribute;
          break;
        case 'h':
          $this->height = $attribute;
          break;

        default:
          $this->setData($attname, $attributes);
          break;
      }
    }
    if (empty($this->getTitle())) {
      $this->setTitle($this->getFileName());
    }

    // If width or height are not specified, get it from img directly. (Slow).
    if (empty($this->width) || empty($this->height)) {
      if (is_file($this->getRealPath())) {
        $realpath = $this->getRealPath();
        if ($realpath === false) {
          error_log("Error: getRealPath() returned false for ". $this->getFileName() . ".");
        } else {
          error_log("Path: " . $realpath);
    	}
	$get_size = getimagesize($this->getRealPath());
    	if ($get_size === false) {
        	error_log("Error: getimagesize() failed for path: " . $realpath);
    	} else {
        	$this->width = $get_size[0];
        	$this->height = $get_size[1];
    	}
      }
    }
  }

  /**
   * Set image's title.
   * @param $title
   */
  public function setTitle($title) {
    $this->title = $title;
  }

  /**
   * Return Image's title.
   * @return string
   */
  public function getTitle() {
    return $this->title;
  }

  /**
   * Generate a thumbnail of an image.
   *
   * @param Environment $env
   *   The Environment.
   * @param array $vars
   *   An array of the variables for the thumbnail to generate.
   * @return string
   *   The url of the generated thumbnail.
   */
  public function generateThumbnail(Environment $env, array $vars) {

    $maxw = isset($vars['w_max']) ? $vars['w_max'] : 0;
    $maxh = isset($vars['h_max']) ? $vars['h_max'] : 0;
    $img_action = isset($vars['operation']) ? $vars['operation'] : 0;
    $compression = isset($vars['compression']) ? $vars['compression'] : 70;
    $fallback = isset($vars['fallback']) ? $vars['fallback'] : 0;

    if ($maxw == 'auto') {
        $maxw = 0;
    }
    if ($maxh == 'auto') {
        $maxh = 0;
    }

    if (!(intval($maxw) > 0)) {
        $maxw = 999999;
    }
    if (!(intval($maxh) > 0)) {
        $maxh = 999999;
    }

    // Get the File path for the image
    $thumb_root = $env->dir['thumbs'];
    $thumbfile = 'thumb-' . str_replace(' ', '-', str_replace('/', '-', $this->getNode()->getName() . '-' . $this->width . 'x' . $this->height . '-' . $this->getName()));

    $img_path = $this->getRealPath();
    $thumb_image_path = $thumb_root . '/' . $thumbfile;

    // TODO: a better cache system (refresh cache when image changes, also with same filename)
    // If thumbnail exists, use it.
    if (is_file($thumb_image_path)) {
        return $thumbfile;
    }
    // If the image file is broken, use the default broken image.
    if (!is_file($img_path)) {
        // Check if if set a fallback image.
        if (isset($fallback) && is_file($fallback)) {
            // Set custom fallback image.
            $img_path = $fallback;
            $fallbackArr = explode('.', $fallback);
            $fallbackExtension = strtolower(end($fallbackArr));

            // New thumbfile.
            $thumbfile .= 'fallback.' . $fallbackExtension;
            $thumb_image_path = $thumb_root . '/' . $thumbfile;
        } else {
            // Set Quanta default fallback image.
            $img_path = $this->env->getModulePath('image') . '/assets/broken-img.jpg';
        }
    }

    // If you want exact dimensions, you
    // will pass 'width' and 'height'

    $img_thumb_width = (int)$maxw;
    $img_thumb_height = (int)$maxh;

    // If you want proportional thumbnails,
    // you will pass 'maxw' and 'maxh'

    $img_max_width = (int)$maxw;
    $img_max_height = (int)$maxh;

    // Based on the above we can tell which
    // type of resizing our script must do

    // The 'scale' type will make the image
    // smaller but keep the same dimensions

    // The 'crop' type will make the thumbnail
    // exactly the width and height you choose

    // To start off, we will create a copy
    // of our original image in $img

    $img = NULL;

    // Based on the image type, create the image object.
    $pathArr = explode('.', $img_path);
    $img_extension = strtolower(end($pathArr));

    if ($img_extension == 'jpg' || $img_extension == 'jpeg') {
        $img = imagecreatefromjpeg($img_path) or print("Cannot create new JPEG image: " . $img_path);
    } elseif ($img_extension == 'png') {
        $img = imagecreatefrompng($img_path) or print("Cannot create new PNG image: " . $img_path);
    } elseif ($img_extension == 'gif') {
        $img = imagecreatefromgif($img_path) or print("Cannot create new GIF image: " . $img_path);
    } elseif ($img_extension == 'webp') {
        $img = imagecreatefromwebp($img_path) or print("Cannot create new WEBP image: " . $img_path);
    }

    // header("Content-type: image/" . $img_extension);

    // If the image has been created, we may proceed
    // to the next step

    if ($img) {
        // We now need to decide how to resize the image

        // If we chose to scale down the image, we will
        // need to get the original image proportions

        $img_orig_width = imagesx($img);
        $img_orig_height = imagesy($img);

        if ($img_action == 'scale') {
            // Get scale ratio

            $scale = min($img_max_width / $img_orig_width,
                $img_max_height / $img_orig_height);

            // To explain how this works, say the original
            // dimensions were 200x100 and our max width
            // and height for a thumbnail is 50 pixels.
            // We would do $img_max_width/$img_orig_width
            // (50/200) = 0.25
            // And $img_max_height/$img_orig_height
            // (50/100) = 0.5

            // We then use the min() function
            // to find the lowest value.

            // In this case, 0.25 is the lowest so that
            // is our scale. The thumbnail must be
            // 1/4th (0.25) of the original image

            if ($scale < 1) {
                // We must only run the code below
                // if the scale is lower than 1
                // If it isn't, this means that
                // the thumbnail we want is actually
                // bigger than the original image

                // Calculate aspect ratios

                $target_aspect_ratio = $img_max_width / $img_max_height;
                $original_aspect_ratio = $img_orig_width / $img_orig_height;

                if ($original_aspect_ratio < $target_aspect_ratio) {
                  // Taller image (narrower aspect ratio) - scale by width
                  $img_new_width = $img_max_width;
                  $img_new_height = floor($img_max_width / $original_aspect_ratio);
                } else {
                  // Wider or equal image - scale by height
                  $img_new_height = $img_max_height;
                  $img_new_width = floor($img_max_height * $original_aspect_ratio);
                }

                // Create a new temporary image using the
                // imagecreatetruecolor function

                $tmpimg = imagecreatetruecolor($img_new_width, $img_new_height);

                if ($img_extension == 'png' || $img_extension == 'webp') {
                    // Preserve transparency when scaling PNG or WEBP
                    imagealphablending($tmpimg, false);
                    imagesavealpha($tmpimg, true);
                    $transparent = imagecolorallocatealpha($tmpimg, 255, 255, 255, 127);
                    imagefilledrectangle($tmpimg, 0, 0, $img_new_width, $img_new_width, $transparent);
                }

                // The function below copies the original
                // image and re-samples it into the new one
                // using the new width and height

                imagecopyresampled($tmpimg, $img, 0, 0, 0, 0,
                    $img_new_width, $img_new_height, $img_orig_width, $img_orig_height);

                // Finally, we simply destroy the $img file
                // which contained our original image
                // so we can replace it with the new thumbnail

                imagedestroy($img);
                $img = $tmpimg;

            } elseif ($img_extension == 'png' || $img_extension == 'webp') {
                // Preserve transparency for non-scaled PNG or WEBP
                imagealphablending($img, true);
                imagesavealpha($img, true);
            }

        } elseif ($img_action == "crop") {
            // Get scale ratio

            $scale = max($img_thumb_width / $img_orig_width,
                $img_thumb_height / $img_orig_height);

            // This works similarly to other one but
            // rather than the lowest value, we need
            // the highest. For example, if the
            // dimensions were 200x100 and our thumbnail
            // had to be 50x50, we would calculate:
            // $img_thumb_width/$img_orig_width
            // (50/200) = 0.25
            // And $img_thumb_height/$img_orig_height
            // (50/100) = 0.5

            // We then use the max() function
            // to find the highest value.

            // In this case, 0.5 is the highest so that
            // is our scale. This is the first step of
            // the image manipulation. Once we scale
            // the image down to 0.5, it will have the
            // dimensions of 100x50. At this point,
            // we will need to crop the image, leaving
            // the height identical but halving
            // the width to 50

            // Calculate the new height and width
            // based on the scale

            $img_new_width = floor($scale * $img_orig_width);
            $img_new_height = floor($scale * $img_orig_height);
            // Create a new temporary image using the
            // imagecreatetruecolor function

            $tmpimg = imagecreatetruecolor($img_new_width, $img_new_height);
            $tmp2img = imagecreatetruecolor($img_thumb_width, $img_thumb_height);

            if ($img_extension == 'png' || $img_extension == 'webp') {
                // Preserve transparency when scaling & cropping PNG or WEBP
                // $tmpimg
                imagealphablending($tmpimg, false);
                imagesavealpha($tmpimg, true);
                $transparent = imagecolorallocatealpha($tmpimg, 255, 255, 255, 127);
                imagefilledrectangle($tmpimg, 0, 0, $img_new_width, $img_new_width, $transparent);
                // $tmp2img
                imagealphablending($tmp2img, false);
                imagesavealpha($tmp2img, true);
                $transparent = imagecolorallocatealpha($tmp2img, 255, 255, 255, 127);
                imagefilledrectangle($tmp2img, 0, 0, $img_new_width, $img_new_width, $transparent);
            }

            // The function below copies the original
            // image and re-samples it into the new one
            // using the new width and height

            imagecopyresampled($tmpimg, $img, 0, 0, 0, 0,
                $img_new_width, $img_new_height, $img_orig_width, $img_orig_height);

            // Our $tmpimg will now have the scaled down
            // image. The next step is cropping the picture to
            // make sure it's exactly the size of the thumbnail

            // The following logic chooses how the image
            // will be cropped. Using the previous example, it
            // needs to take a 50x50 block from the original
            // image and copy it over to the new thumbnail

            // Since we want to copy the exact center of the
            // scaled down image, we need to find out the x
            // axis and y axis. To do so, say the scaled down
            // image now has a width of 100px but we want it
            // to be only 50px

            // Somehow, we need to select between the 25th and
            // 75th pixel to copy the middle.

            // To find this value we do:
            // ($img_new_width/2)-($img_thumb_width/2)

            // ( 100px / 2 ) - (50px / 2)
            // ( 50px ) - ( 25px )
            // = 25px

            $x_axis = 0;
            $y_axis = 0;

            if ($img_new_width == $img_thumb_width) {
                $y_axis = ($img_new_height / 2) - ($img_thumb_height / 2);
                $x_axis = 0;
            } elseif ($img_new_height == $img_thumb_height) {
                $y_axis = 0;
                $x_axis = ($img_new_width / 2) - ($img_thumb_width / 2);
            }

            // We now have to resample the new image using the
            // new dimensions and axis values.

            imagecopyresampled($tmp2img, $tmpimg, 0, 0,
                (int)$x_axis, (int)$y_axis,
                $img_thumb_width,
                $img_thumb_height,
                $img_thumb_width,
                $img_thumb_height);

            imagedestroy($img);
            imagedestroy($tmpimg);
            $img = $tmp2img;

        } elseif ($img_extension == 'png' || $img_extension == 'webp') {
            // Preserve transparency for non-cropped PNG or WEBP
            imagealphablending($img, true);
            imagesavealpha($img, true);
        }

        // Display the image using the header function to specify
        // the type of output our page is giving
        if ($img_extension == 'jpg' || $img_extension == 'jpeg') {
            imagejpeg($img, $thumb_image_path, $compression);
        } elseif ($img_extension == 'png') {
            imagepng($img, $thumb_image_path, ($compression / 10));
        } elseif ($img_extension == 'gif') {
            imagegif($img, $thumb_image_path);
        } elseif ($img_extension == 'webp') {
            imagewebp($img, $thumb_image_path, $compression);
        }

    }
    return $thumbfile;
}


   /**
   * Chek if the Image is valid.
   * 
   * @return boolean
   *   Check if the image exists and valid.
   */
  public function isValid() {
    $valid_img= true;
    if (!$this->exists) {
      // If image file doesn't exist
      $valid_img= false;
    } elseif(!getimagesize($this->getRelativePath())) {
      // If the file exists, check if it's a valid image
      $valid_img= false;
    }
    return $valid_img;
  }
} 
