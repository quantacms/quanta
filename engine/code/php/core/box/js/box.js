var resizeBoxes = function() {

    $('.autoresize').each(function() {
        var inner = $(this).find('.inner');
        paddingLeft = parseInt(inner.css('padding-left').replace("px", ""));
        paddingRight = parseInt(inner.css('padding-right').replace("px", ""));
	    paddingTop = parseInt(inner.css('padding-top').replace("px", ""));
        paddingBottom = parseInt(inner.css('padding-bottom').replace("px", ""));

        marginLeft = parseInt(inner.css('margin-left').replace("px", ""));
        marginRight = parseInt(inner.css('margin-right').replace("px", ""));
		marginTop = parseInt(inner.css('margin-top').replace("px", ""));
        marginBottom = parseInt(inner.css('margin-bottom').replace("px", ""));

        borderLeftWidth = parseInt(inner.css('border-left-width').replace("px", ""));
        borderRightWidth = parseInt(inner.css('border-right-width').replace("px", ""));
        borderTopWidth = parseInt(inner.css('border-top-width').replace("px", ""));
		borderBottomWidth = parseInt(inner.css('border-bottom-width').replace("px", ""));

        var parentWidth = $(this).parent().innerWidth();

        var w = $(this).innerWidth();


        // used for mobile rendering.
        var wclass;

        $(this).removeClass('w-75-100').removeClass('w-50-75').removeClass('w-25-50').removeClass('w-0-25');
        if (w >= parentWidth / 100 * 75) {wclass = 'w-75-100'; }
        else if (w >= parentWidth / 100 * 50) {wclass = 'w-50-75'; }
        else if (w >= parentWidth / 100 * 25) {wclass = 'w-25-50'; }
        else {wclass = 'w-0-25'; }

        if ($(this).hasClass('h-full')) {
            h = window.innerHeight;
        } else {
            var hclass = $(this).attr("class").match(/h[0-9]*\b/);
            if (hclass!= null && hclass[0]) {
                ratio = parseInt(100 / hclass[0].replace('h', ''));
                h = parseInt(parentWidth / ratio);

            }
            else {
                return;
            }
        }

        $(this).addClass(wclass);
		$(this).css('height', h);

        var innerh = parseInt(h - marginTop - marginBottom - paddingTop - paddingBottom  - borderTopWidth - borderBottomWidth);
        var innerw = parseInt(w - marginLeft - marginRight - paddingLeft - paddingRight - borderLeftWidth - borderRightWidth);
        $(this).children('.inner').css('height', innerh + 'px');
        $(this).children('.inner').css('width', innerw + 'px');


    });

}

$(window).on('resize', function() {
    resizeBoxes();
});

$(document).ready(function() {
  resizeBoxes();
});


$(document).bind('refresh', function() {
    resizeBoxes();
    $('.close-button').off('click').on('click', function(ev) {
        $(this).closest('.box').hide();
        ev.preventDefault();
    });
});
