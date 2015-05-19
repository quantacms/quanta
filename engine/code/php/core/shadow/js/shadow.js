// Add/Edit page form.
// TODO: this has all to be refactored.

function pageEdit(action) {
    $('#shadow-image').hide();
    openShadow('node_edit');
    $('#shadow-outside').fadeIn('slow');
}

function pageDelete() {
    openShadow('page_delete');
}

function openShadow(context) {
    $('#shadow-item').html('').attr('rel', context).load('?shadow=' + context, function() {
        pageRefresh();
        refreshButtons();
    });
    $('#shadow-outside').fadeIn('slow');
}

function logIn() {
    openShadow('user_login');
}

function logOut() {
    $('#shadow-item').html('<form id="shadow-login" method="POST"><input type="hidden" id="login-action" name="action" value="logout" /><input type="submit" id="login-submit" name="submit" /></form>');
    $('#login-submit').click();
}

function shadowSubmitSuccess(data) {
    if (data.redirect == 'undefined') {
        alert("There was an error with your submission.");
    } else {
        top.location.href = data.redirect;
    }
}

function shadowSubmitError(err, exception) {
    alert(exception);
}
function shadowSubmit() {
    var form_items = {};
    $('#shadow-edit-page').find('input, textarea, select').each(function() {
        form_items[$(this).attr('name')] = $(this).val();
    });
    var formData = JSON.stringify(form_items);

    if (confirm('Are you sure you want to ' + form_items.action.replace('node_', '') + ' this item?')) {
        //$('body').html(formData);
        //return;
        $.ajax({
            type: "POST",
            dataType: 'json',
            url: '/',
            data: {json: formData},
            success: shadowSubmitSuccess,
            error: shadowSubmitError
        });
    }
}
