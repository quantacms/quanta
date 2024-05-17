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
 * Request to open an user edit form.
 */
function user_edit(user_action) {
  openShadow({
    module: 'user',
    context: user_action,
    widget: 'single',
    components: ['user_edit_form']
  });
};

/**
 * Request to open a reset password form.
 */
function resetPassword() {
  openShadow({
    module: 'user',
    context: 'user_reset_password',
    widget: 'single',
    components: ['user_reset_password_form']
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
    user_edit('user_edit');
    e.preventDefault();
  });
  // User edit.
  $('.user-edit-own-link').click(function (e) {
    user_edit('user_edit_own');
    e.preventDefault();
  });
  // Register.
  $('.register-link').click(function (e) {
    user_edit('user_register');
    e.preventDefault();
  });
   // Reset Password.
   $('.reset-password-link').click(function (e) {
    resetPassword();
    e.preventDefault();
  });
});
