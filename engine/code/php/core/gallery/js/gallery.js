// Inizializza.
$('document').ready(function() {
    pageRefresh();
    // TODO: refresh thumbs only if gallery available.
    thumbsRefresh();
});

/**
 * Created by aldotripiciano on 11/05/15.
 */
// Gallery functions.
function thumbsRefresh() {
    $('a.gallery-thumb').bind('click', function() {
        galleryOpen($(this));
        return false;
    });
    $('#shadow-inside, #shadow-image').bind('click', function() {
        $('#shadow-outside, .shadow-element').hide();
    });
    $(document).keydown(function(e) {
        if (!($('#shadow-image').length)) {return;}
        switch (e.which) {
            case 37: // left
                alert($('.gallery-thumb-selected').closest('li').html());
            case 39: // right
                galleryOpen($('.gallery-thumb-selected').closest('.gallery-thumb').next());
                break;
        }
    });
}

function galleryOpen(thumb) {
    $('.gallery-thumb-selected').removeClass('gallery-thumb-selected');
    $(thumb).addClass('gallery-thumb-selected');
    $('#shadow-form').hide();
    $('#shadow-outside').show();
    var pt = $(thumb).attr('href').split('/');
    var fn = pt[pt.length-1].split('.');
    $('#shadow-item').html('<div id="shadow-image"><img src="' + thumb.attr('href') + '" /></div><div id="shadow-text">' + fn[0] + '</div>');
    return false;
}
