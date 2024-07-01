<?php
namespace Quanta\Qtags;

/**
 * Class FormItemRating
 * This class represents a Form Item of type rating
 */
class FormItemRating extends FormItemString {
  public $type = 'rating';
  protected $html_tag = 'div';


    /**
   * Renders a form item as HTML.
   *
   * @return string
   *   The rendered form item.
   */
  public function render() {
    $max = !empty($this->getAttribute('max')) ? $this->getAttribute('max') : 5;
    $plugin =  $this->getAttribute('plugin');
    $form_item_name =  $this->getName();
    $value = $this->getValue(true);
    $html_body = "<div class=\"$plugin-rating\">";
    switch ($plugin) {
        case 'stars': 
        default:
        for ($i=$max; $i >=1 ; $i--) { 
            $checked = ($value == $i) ? 'checked' : '';
            $html_body .= "<input type=\"radio\" id=\"$form_item_name-star$i\" name=\"$form_item_name\" value=\"$i\" $checked><label for=\"star$i\" title=\"$i stars\">★</label>";        }
            break;
    }
    $html_body .= '</div>';

    $this->html_body = $html_body;
    // Return the full rendered form item.
    return parent::render();
  }



 
}
