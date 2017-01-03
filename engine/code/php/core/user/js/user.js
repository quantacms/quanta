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
    openShadow({ 
						module : 'user',
						context: 'user_register',
						widget: 'single',
						components: ['user_register_form']
						});
}

$(document).ready(function() {
    $('.register-link').click(function (e) {
        register();
        e.defaultPrevented();
    });
    $('.login-link').click(function (e) {
        logIn();
        e.defaultPrevented();
    });
    $('.logout-link').click(function(e) {
        logOut();
        e.defaultPrevented();
    });
    $('.user-edit-link').click(function (e) {
        openShadow({ module: 'user', context: 'user_edit', widget: 'tabs'});
        e.defaultPrevented();
    });
});
