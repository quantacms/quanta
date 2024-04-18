<?php
namespace Quanta\Qtags;

/**
 * Create a Gallery using all images contained in a node.
 */
class Gallery extends HtmlTag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $this->attributes['file_types'] = 'image';
    $filelist = new \Quanta\Common\FileList($this->env, $this->getTarget(), 'gallery', $this->attributes, 'gallery');
    $this->html_body = $filelist->render();

    $css_attributes = array();
    $gallery_css = new Css($this->env, $css_attributes, $this->env->getModulePath('gallery') . '/assets/css/gallery.css');
    $this->html_body .= $gallery_css->render();
    /** @var \Quanta\Common\Page $page */
    $page = $this->env->getData('page');

    $page->addCSS($this->env->getModulePath('gallery') . '/assets/css/gallery.css');

    return parent::render();
  }
}
