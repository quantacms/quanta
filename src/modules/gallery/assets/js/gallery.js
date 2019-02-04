/**
 * Add handlers to Gallery thumbnails for opening in overlay.
 * Created by aldotripiciano on 11/05/15.
 */
$(document).ready(function() {

  // Inizialize galleries only if there are items.
    if ($('.list-gallery-item').length > 0) {

        alert("FINE");
        // Gallery functions.
        $('.list-gallery a.gallery-thumb').on('click', function(e) {
            alert("OK");
            galleryOpen($(this));
            e.preventDefault();
        });

        $(document).keydown(function(e) {
            if (!($('#shadow-image').length)) {return;}
            var selection = $('.gallery-thumb-selected');
            var gallery = selection.parents('ul');
            var index = parseInt(selection.parents('li').attr('index'));
            var total = gallery.find('li').length;

            var nextItem = [];
            nextItem[37] = (index == 1) ? total : (index - 1);
            nextItem[39] = (index == total) ? 1 : (index + 1);
            //console.log('key:',e.which);
            switch (e.which) {
                case 27: // esc
                    closeShadow();
                    break;
                /*
                case 37: // left
                case 39: // right
                    galleryOpen(gallery.find('.list-item-' + nextItem[e.which]).find('.gallery-thumb'));
                    break;*/
            }
        });

        /**
         * Open a gallery thumbnail into the shadow.
         *
         * TODO: not a proper way to user shadow overlay!
         *
         * @param thumb
         * @returns {boolean}
         */
        function galleryOpen(thumb) {
            //console.log('thumb', thumb);
            $('.gallery-thumb-selected').removeClass('gallery-thumb-selected');
            $(thumb).addClass('gallery-thumb-selected');
            $('#shadow-outside').show(); 
            var pt = $(thumb).attr('href').split('/');
            var fn = pt[pt.length-1].split('.');

            $('#shadow-item').html('<a id="gallery-close" href="#"><i class="fa fa-times" aria-hidden="true"></i></a><div id="shadow-image"><img src="' + thumb.attr('href') + '" /></div><div id="shadow-text">' + fn[0] + '</div>');

            $('#gallery-close').on('click', function(e){
                closeShadow();
                e.preventDefault();
            });
            return false;
        };
    }
});