<?php
namespace Quanta\Qtags;

/**
 * Class FormItemFile
 * This class represents a Form Item of type File upload
 */
class Captcha extends Qtag {

  /**
   * Renders.
   * @return mixed
   */
  function render() {
    $page = $this->env->getData('page');
    $page->addJS('https://www.google.com/recaptcha/api.js', 'file');
    $page->addJS('/src/modules/captcha/assets/js/captcha.js', 'file');

    $html = '<div class="g-recaptcha" data-sitekey="[ENV|key=CAPTCHA_SITE_KEY]"></div>
            <span class="hidden captcha-warning" style="color: red;text-align: left;">[TEXT|tag=captcha-alert:Si prega di completare il CAPTCHA]</span>';
    return $html;
}

}
