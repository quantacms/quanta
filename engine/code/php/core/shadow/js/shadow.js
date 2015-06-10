// Add/Edit page form.
// TODO: this has all to be refactored.

function pageEdit(action) {
    openShadow({ module : 'node', context: action, type: 'tabs'});
}

function pageDelete() {
    openShadow({ module : 'node', context: 'node_delete', type: 'tabs'});
}

function openShadow(shadow) {
    if (shadow.type == 'undefined') {
        shadow.type = 'tabs';
    }
    $('#shadow-item').html('').attr('rel', shadow.context).load(
        '?shadow=' + JSON.stringify(shadow), function() {
        $.getScript('/engine/code/php/core/file/js/jquery.fileupload.js');
        $.getScript('/engine/code/php/core/file/js/file-upload.js');
        pageRefresh();
        refreshButtons();
        $('.shadow-title').find('a').on('click', function () {
            if (!($(this).parent().hasClass('enabled'))) {
                $('.enabled').removeClass('enabled');
                $(this).parent().addClass('enabled');
                $('#shadow-content-' + $(this).attr('rel')).addClass('enabled');
            }
            return false;
        });
    });
    $('#shadow-outside').fadeIn('slow');
}

/**
 * Submit a shadow form.
 */
function shadowSubmit() {
    var form_items = {};
    $('#shadow-outside').find('input, textarea, select').each(function() {
        form_items[$(this).attr('name')] = $(this).val();
    });
    var formData = JSON.stringify(form_items);
    action(formData);
}


