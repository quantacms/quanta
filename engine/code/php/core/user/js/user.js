function logIn() {
    openShadow({ module : 'user', context: 'user_login', type: 'single'});
}

function logOut() {
    action('{"action": "logout"}');
}

function register() {
    openShadow({ module : 'user', context: 'user_register', type: 'single'});
}

$(document).ready(function() {
    $('.user-edit').unbind().bind('click', function () {
        openShadow({ module: 'user', context: 'user_edit', type: 'tabs'});
    });
});
