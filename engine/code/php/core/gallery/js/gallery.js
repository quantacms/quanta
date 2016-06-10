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
        top.location.href='#';
        galleryOpen($(this));
        return false;
    });

    $(document).keydown(function(e) {
        if (!($('#shadow-image').length)) {return;}
        switch (e.which) {
            case 37: // left
                galleryOpen($('.gallery-thumb-selected').prev('.gallery-thumb').prev('.gallery-thumb'));
            case 39: // right
                galleryOpen($('.gallery-thumb-selected').next('.gallery-thumb'));
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
    $('#shadow-inside').click(function() {
       $('#shadow-outside').hide();
    });
    return false;

}
