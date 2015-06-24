// Add/Edit page form.
// TODO: this has all to be refactored.

/**
 * Page edit action. TODO: move in page module.
 */
function pageEdit(action) {
    openShadow({ module : 'node', context: action, type: 'tabs'});

}

/**
 * Page delete action. TODO: move in page module.
 */
function pageDelete() {
    openShadow({ module : 'node', context: 'node_delete', type: 'single'});
}

/**
 * Open a shadow (lightbox) with the specified parameters.
 * @param shadow
 */
function openShadow(shadow) {
    if (shadow.type == undefined) {
        shadow.type = 'tabs';
    }
    console.log(shadow);
    $('#shadow-item').html('').attr('rel', shadow.context).load(
        '?shadow=' + JSON.stringify(shadow), function() {

        pageRefresh();
        refreshButtons();
            // Include attached scripts if present.
            if (shadow.attach != undefined) {
                for (i = 0; i < shadow.attach.length; i++) {
                    $.getScript(shadow.attach[i]);
                }
            }
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
    // TODO: this goes into ckeditor.
    if ($('textarea#content').length) {
    var editor = CKEDITOR.instances.content;
    var edata = editor.getData();
    $('#content').html('<!--@nobr-->' + edata.replace('<!--@nobr-->', ''));
    }
    var form_items = {};
    $('#shadow-outside').find('input, textarea, select').each(function() {
        form_items[$(this).attr('name')] = $(this).val();
    });
    var formData = JSON.stringify(form_items);
    action(formData);
}


