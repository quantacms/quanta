<?php
/**
 * Class File
 * This class represents a Page (corrisponding to a rendered html page).
 */
class File {
  public $env;
  public $name;
  public $path;
  public $extension;
  public $type;
  public $exists;
  public $size;
  /**
   * @var Node
   */
  public $node;

  // Construct the Node.
  public function __construct(&$env, $file_path, $node = NULL) {
    $fpath_full = explode('/', $file_path);
    $file_path = $fpath_full[count($fpath_full) - 1];
    $this->path = '/' . $node->getName() . '/' . $file_path;
    $exp = explode('.', $file_path);
    $this->extension = strtolower($exp[count($exp) - 1]);
    $this->name = $exp[0];
    $this->type = File::getFileType($this->extension);
    $this->node = $node;
    $this->exists = is_file($this->node->realpath . '/' . $file_path);
  }

  /**
   * Check if the file is public (meaning it can be viewed / downloaded).
   * System files such as .access .userdata and data.html should never be
   * public.
   *
   * @return bool
   */
  public function isPublic() {
    $is_public =
      ($this->getType() != 'swap') &&
      ($this->extension != 'access') &&
      ($this->name != '') &&
      ($this->name != 'data');
    return $is_public;
  }


  public function getFileSize() {
    return $this->size;
  }

  public function getType() {
    return $this->type;
  }

  public function getPath() {
    return $this->path;
  }
  public function getExtension() {
    return $this->extension;
  }

  /**
   * Determine the file "type" from its extension.
   * @param $extension String
   * @return string
   */
  static function getFileType($extension) {
    switch ($extension) {
      case 'jpg':
      case 'png':
      case 'bmp':
      case 'gif':
      case 'jpeg':
      case 'ico':
      case 'raw':
        $type = 'image';
        break;

      case 'mp3':
      case 'wav':
      case 'm4a':
      case 'wma':
        $type = 'audio';
        break;

      case 'avi':
      case 'mov':
      case 'mp4':
      case 'mpg':
      case 'mpeg':
        $type = 'video';
        break;

      case 'html':
      case 'htm':
        $type = 'html';
        break;

      case 'doc':
      case 'odt':
      case 'docx':
      case 'txt':
      case 'rtf':
        $type = 'document';
        break;

      case 'pdf':
        $type = 'pdf';
        break;

      case 'xls':
      case 'xml':
        $type = 'sheet';
        break;

      case 'zip':
      case 'tex':
      case 'rar':
      case 'gz':
      case 'tar':
      case '7z':
        $type = 'archive';
        break;

      case 'swp':
        $type = 'swap';
        break;

      default:
        $type = 'data';
        break;
    }
    return $type;
  }

  public function render() {
    return $this->path;
  }

  public function getName() {
    return $this->name;
  }

}