<?php
namespace Quanta\Qtags;

/**
 * Class FormItemAutocomplete
 * This class represents a Form Item of type dropdown Select
 */
class FormItemAutocomplete extends FormItemString {

  function render() {
    if(empty($this->getAttribute('hide-css'))){
      $module_path = $this->env->getModulePath('form');
      /** @var \Quanta\Common\Page $page */

      $css_attributes = array();
      $autocomplete_css = new Css($this->env, $css_attributes, $module_path . '/addons/autocomplete/easy-autocomplete.min.css');
      $autocomplete_css_themes = new Css($this->env, $css_attributes, $module_path . '/addons/autocomplete/easy-autocomplete.themes.min.css');

      $this->getFormState()->attach('form_autocomplete_css', $autocomplete_css->render() . $autocomplete_css_themes->render());
     
    }
    if(!empty($this->getAttribute('list_filter'))){
      $this->html_params['data-list-filter'] =  $this->getAttribute('list_filter');
    }
    $this->addClass('autocomplete');
    return parent::render();
  }
}
