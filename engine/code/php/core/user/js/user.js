function logIn() {
    openShadow({
        module : 'user',
        context: 'user_login',
        widget: 'single',
        components: ['user_login_form']
    });
}

function logOut() {
    action('{"action": "logout"}');
}

function register() {
    openShadow({ module : 'user', context: 'user_register', widget: 'single'});
}

$(document).ready(function() {
    $('.register-link').click(function () {
        register();
    });
    $('.login-link').click(function () {
        logIn();
    });
    $('.logout-link').click(function() {
        logOut();
    });
    $('.user-edit-link').click(function () {
        openShadow({ module: 'user', context: 'user_edit', widget: 'tabs'});
    });
});