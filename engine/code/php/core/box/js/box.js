var resizeBoxes = function() {
    var margin = 20;
    var padding = 10;
    var borderWidth = 3;
    $('.box').each(function() {
        var w = $(this).innerWidth();

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

        else {
            $(this).children('.inner').css('margin', margin).css('padding', padding).css('border-width', borderWidth);
            return;
        }

        $(this).css('height', h) + margin;
        $(this).children('.inner').css('height', h - (margin * 2) - (padding * 2) - (borderWidth * 2));
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