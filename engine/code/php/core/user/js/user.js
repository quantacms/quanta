/**
 * Request to open a login form.
 */
function logIn() {
  openShadow({
    module: 'user',
    context: 'user_login',
    widget: 'single',
    components: ['user_login_form']
  });
};

/**
 * Request to open a logout form.
 */
function logOut() {
  action('{"action": "logout"}');
};

/**
 * Request to open an user registration form.
 */
function register() {
  openShadow({
    module: 'user',
    context: 'user_register',
    widget: 'single',
    components: ['user_edit_form']
  });
};

/**
 * Request to open an user edit form.
 */
function user_edit() {
  openShadow({
    module: 'user',
    context: 'user_edit',
    widget: 'single',
    components: ['user_edit_form']
  });
};

/**
 * When opening user login, put the focus on username field.
 */
$(document).bind('shadow_user_login', function () {
  $('#username').focus();
});

/**
 * Assign all click events.
 */
$(document).ready(function () {
  // Login.
  $('.login-link').click(function (e) {
    logIn();
    e.preventDefault();
  });
  // Logout.
  $('.logout-link').click(function (e) {
    logOut();
    e.preventDefault();
  });
  // User edit.
  $('.user-edit-link').click(function (e) {
    user_edit();
    e.preventDefault();
  });
  // Register.
  $('.register-link').click(function (e) {
    register();
    e.preventDefault();
  });
});
