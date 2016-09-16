var refreshJumpers = function() {
    $('.jumper').each(function() {
        $(this).bind('change', function() {
            var rel = $(this).attr('rel');

            if (rel == '_self') {
                top.location.href = '/' + $(this).val();
            } else {
                openAjax('/' + $(this).val(), rel, 'refreshJumpers');
            }
        });
    });
}

$(document).ready(function() {
    refreshJumpers();
});

