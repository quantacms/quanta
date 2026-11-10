<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;

/**
 * Class InlineUpload
 * Supports inline replacement/upload of single or multiple files (e.g. node thumbnail, user avatar)
 * using Shadow's single-tab display mode (#163).
 */
class InlineUpload extends Qtag {
  public function render() {
    $node = NodeFactory::loadOrCurrent($this->env, $this->getAttribute('node'));
    if (!$node || !$node->exists) {
      return '';
    }
    $isSingle = $this->getAttribute('single') !== 'false';
    $field = $this->getAttribute('field') ?: 'thumbnail';
    $label = $this->getAttribute('title') ?: ($isSingle ? 'Replace File' : 'Upload Files');
    $nodeName = htmlspecialchars($node->getName(), ENT_QUOTES);
    $singleFlag = $isSingle ? 'true' : 'false';

    $html = '<a href="javascript:void(0);" class="quanta-inline-upload-btn edit-link" '
      . 'data-node="' . $nodeName . '" '
      . 'data-field="' . htmlspecialchars($field, ENT_QUOTES) . '" '
      . 'data-single="' . $singleFlag . '" '
      . 'onclick="if(typeof openShadow === \'function\'){ openShadow({module:\'file\', context:\'inline_upload\', widget:\'single\', components:[\'file_upload\'], node:\'' . $nodeName . '\', single:' . $singleFlag . ', field:\'' . htmlspecialchars($field, ENT_QUOTES) . '\'}); }">'
      . htmlspecialchars($label)
      . '</a>';

    return $html;
  }
}
