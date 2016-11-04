var shadow;
// Add/Edit page form.

function closeShadow() {
    $('#shadow-outside, .shadow-element').hide();
}

/**
 * Open a shadow (lightbox) with the specified parameters.
 * @param shadow
 */
function openShadow(shadowData) {
    shadow = shadowData;
    if (shadow.widget == undefined) {
        shadow.widget = 'tabs';
    }
    var node = (shadow.node.length > 0) ? shadow.node : '';
    $('#shadow-item').html('').attr('rel', shadow.context).load(
        '/' + node + '/?shadow=' + JSON.stringify(shadow), function () {
            if (shadow.callback != undefined) {
                shadow.callback();
            }

            $('#shadow-inside, #shadow-image').bind('click', function () {
                closeShadow();
            });

            $(document).trigger('refresh');

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
            $(document).trigger('shadow_' + shadow.context);
        });
    $('#shadow-outside').fadeIn('slow');
}

/**
 * Submit a shadow form.
 */
function submitShadow() {
    $(document).trigger('shadow_' + shadow.context + '_submit');
    var form_items = {};
    $('#shadow-outside').find('input, textarea, select').each(function () {
        form_items[$(this).attr('name')] = $(this).val();
    });
    var formData = JSON.stringify(form_items);
    action(formData);
}


