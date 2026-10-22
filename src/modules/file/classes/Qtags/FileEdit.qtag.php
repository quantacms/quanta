<?php

namespace Quanta\Qtags;

/**
 * Creates an inline file-edit link backed by a single-page Shadow form.
 */
class FileEdit extends Edit
{
    public $link_class = array('edit-link', 'file-edit-link');

  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered edit link.
   */
    public function render()
    {
        $field = $this->getAttribute('field', 'files');
        if (!is_string($field) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $field)) {
            $field = 'files';
        }

        $multiple = $this->hasAttribute('multiple') && $this->getAttribute('multiple') !== 'false';
        $thumbnail = $this->hasAttribute('thumbnail') && $this->getAttribute('thumbnail') !== 'false';

        $this->setAttribute('components', 'simple_file_form,node_form');
        $this->setAttribute('widget', 'single');
        $this->setAttribute('file_field', $field);
        $this->setAttribute('single', $multiple ? '' : 'true');
        $this->setAttribute('not_thumbnail', $thumbnail ? '' : 'true');
        $this->setAttribute('set_auto_thumbnail', ($multiple && $thumbnail) ? 'true' : '');
        $this->setAttribute('without_redirect', 'true');

        return parent::render();
    }
}
