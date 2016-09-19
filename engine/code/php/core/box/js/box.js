var resizeBoxes = function() {
    var margin;
    var padding;
    var borderWidth;
    $('.box').each(function() {
        var inner = $(this).find('.inner');
        margin = parseInt(inner.css('margin').replace("px", ""));
        padding = parseInt(inner.css('padding').replace("px", ""));
        borderWidth = parseInt(inner.css('border-top').replace("px", ""));

        var w = $(this).innerWidth();

        // TODO: unpredictable, but we need to start with a 66% width
        // to calculate a height in line with the 33%.

        if ($(this).hasClass('w67')) {
            $(this).css('width', '67%');
        }

            if ($(this).hasClass('h100')) {
            ratio = 1;
            h = w / ratio;
        }

        else if ($(this).hasClass('h50')) {
            ratio = 2;
            h = w / ratio;
        }

        else if ($(this).hasClass('h33')) {
            ratio = 3;
            h = w / ratio;
        }

        else if ($(this).hasClass('h25')) {
            ratio = 4;
            h = w / ratio;
        }

        else {
            $(this).children('.inner').css('margin', margin).css('padding', padding).css('border-width', borderWidth);
            return;
        }

        $(this).css('min-height', h) + margin;
        $(this).children('.inner').css('min-height', h - (margin * 2) - (padding * 2) - (borderWidth * 2));
        $(this).children('.inner').css('margin', margin).css('padding', padding).css('border-width', borderWidth);

    });

    $('#home-slider').css('max-height', window.innerHeight + 'px').css('margin-bottom', margin);
}

$(window).on('resize', function() {
    resizeBoxes();
});

$(document).ready(function() {
    resizeBoxes();
});