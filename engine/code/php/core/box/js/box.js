var resizeBoxes = function() {

    $('.box').each(function() {
        var inner = $(this).find('.inner');

	    paddingTop = parseInt(inner.css('padding-top').replace("px", ""));
        paddingBottom = parseInt(inner.css('padding-bottom').replace("px", ""));
		marginTop = parseInt(inner.css('margin-top').replace("px", ""));
        marginBottom = parseInt(inner.css('margin-bottom').replace("px", ""));
		var parentWidth = $(this).parent().innerWidth();

		borderTopWidth = parseInt(inner.css('border-top-width').replace("px", ""));
		borderBottomWidth = parseInt(inner.css('border-bottom-width').replace("px", ""));

		var w = $(this).innerWidth();

        // used for mobile rendering.
        var wclass;

        $(this).removeClass('w-75-100').removeClass('w-50-75').removeClass('w-25-50').removeClass('w-0-25');
        if (w >= parentWidth / 100 * 75) {wclass = 'w-75-100'; }
        else if (w >= parentWidth / 100 * 50) {wclass = 'w-50-75'; }
        else if (w >= parentWidth / 100 * 25) {wclass = 'w-25-50'; }
        else {wclass = 'w-0-25'; }


        // TODO: unpredictable, but we need to start with a 66% width
        // to calculate a height in line with the 33%.
        var hclass = $(this).attr("class").match(/h[0-9]*\b/);
        if (hclass!= null && hclass[0]) {
            ratio = parseInt(100 / hclass[0].replace('h', ''));
            h = parentWidth / ratio;
        }
        else {
            return;
        }

        $(this).addClass(wclass);
		$(this).css('min-height', h);
        $(this).children('.inner').css('min-height', h - marginTop - marginBottom - paddingTop - paddingBottom  - borderTopWidth - borderBottomWidth);

    });

}

$(window).on('resize', function() {
    resizeBoxes();
});

$(document).ready(function() {
    resizeBoxes();
});
