function logIn() {
    openShadow({ module : 'user', context: 'user_login', type: 'single'});
}

function logOut() {
    action('{"action": "logout"}');
}

function register() {
    openShadow({ module : 'user', context: 'user_register', type: 'single'});
}
