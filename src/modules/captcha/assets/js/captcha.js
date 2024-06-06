$(document).ready(function () {
    $('.g-recaptcha').siblings('#wrapper-form_submit').find('input[type="submit"]').click(function () {
        var response = grecaptcha.getResponse();
        if(response.length == 0) {
            $('.g-recaptcha').siblings('.captcha-warning').show();
            event.preventDefault();
            return false;
        }
        else{
            $('.g-recaptcha').siblings('.captcha-warning').hide();  
            return true;
        }
});
    
});