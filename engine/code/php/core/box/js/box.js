var resizeBoxes = function() {
		var margin;
    var padding;
    var borderWidth;
    $('.box').each(function() {
        var inner = $(this).find('.inner');
        
				// marginLeft = parseInt(inner.css('margin-left').replace("px", ""));
        // marginRight = parseInt(inner.css('margin-right').replace("px", ""));
				// paddingLeft = parseInt(inner.css('padding-left').replace("px", ""));
        // paddingRight = parseInt(inner.css('padding-right').replace("px", ""));
       
				paddingTop = parseInt(inner.css('padding-top').replace("px", ""));
        paddingBottom = parseInt(inner.css('padding-bottom').replace("px", ""));
				
				marginTop = parseInt(inner.css('margin-top').replace("px", ""));
        marginBottom = parseInt(inner.css('margin-bottom').replace("px", ""));

				var parentWidth = $(this).parent().innerWidth();

				borderTopWidth = parseInt(inner.css('border-top-width').replace("px", ""));
				borderBottomWidth = parseInt(inner.css('border-bottom-width').replace("px", ""));
       
				var w = $(this).innerWidth();
        var wratio;
				var hratio;
        var blockwidth;
        // TODO: unpredictable, but we need to start with a 66% width
        // to calculate a height in line with the 33%.

        if ($(this).hasClass('w67')) {
            $(this).css('width', '67%');
        }

				if ($(this).hasClass('w100')) {	wratio = 100;	}
        else if ($(this).hasClass('w75')) { wratio = 75; }
				else if ($(this).hasClass('w50')) { wratio = 50; }
				else if ($(this).hasClass('w33')) { wratio = 33; }
				else if ($(this).hasClass('w25')) { wratio = 25; }
			
				if ($(this).hasClass('h100')) {
            ratio = 1;
            h = parentWidth / ratio;
        }

        else if ($(this).hasClass('h50')) {
            ratio = 2;
            h = parentWidth / ratio;
        }

        else if ($(this).hasClass('h33')) {
            ratio = 3;
            h = parentWidth / ratio;
        }

        else if ($(this).hasClass('h25')) {
            ratio = 4;
            h = parentWidth / ratio;
        }

        else {
            return;
        }
        
				$(this).css('min-height', h) + margin;
        $(this).children('.inner').css('min-height', h - marginTop - marginBottom - paddingTop - paddingBottom  - borderTopWidth - borderBottomWidth);

    });

}

$(window).on('resize', function() {
    resizeBoxes();
});

$(document).ready(function() {
    resizeBoxes();
});
