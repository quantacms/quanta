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
        $min_resolution = $this->getAttribute('min');
        $max_resolution = $this->getAttribute('max');
        if(!empty($min_resolution)|| !empty($max_resolution)){
            $this->addClass('shadow-desc');
            $this->html_body = "[TEXT|tag=resolution-description:dimensioni consigliate:]";
            if (!empty($min_resolution)) {
                $this->html_body .= "[TEXT|tag=min:minima]:" . $min_resolution;
            }
            if (!empty($max_resolution)) {
                $this->html_body .= "[TEXT|tag=max:massimo]:" . $max_resolution;

            }
             $this->html_body .= " pixel";
            return parent::render();
        }
       
    }
}
