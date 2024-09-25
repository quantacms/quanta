<?php
namespace Quanta\Qtags;
use Quanta\Common\FileObject;
use Quanta\Common\NodeFactory;

/**
 * 
 */
class ResolutionMessage extends HtmlTag {
    protected $html_tag = 'p';

    public function render() {
        $resolution = $this->getTarget();
        if(!empty($resolution)){
            $this->addClass('shadow-desc');
            $this->html_body = "[TEXT|tag=resolution-description:dimensioni consigliate:]" . $resolution . " pixel";
            return parent::render();
        }
       
    }
}
