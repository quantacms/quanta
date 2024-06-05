$(document).ready(function () {
    $('.g-recaptcha').siblings('#wrapper-form_submit').find('input[type="submit"]').click(function () {
        var response = grecaptcha.getResponse();
        if(response.length == 0) {
            alert("Please complete the CAPTCHA");
            event.preventDefault();
            return false;
        }
        return true;
});
    
});