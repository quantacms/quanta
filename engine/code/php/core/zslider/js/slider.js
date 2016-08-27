$(document).ready(function() {
    $('.slider').each(function() {
        $(this).find('.list-item-1').addClass('list-item-active');
        checkSlider($(this));
    });
});

var checkSlider = function(slider) {
    var active = slider.find('.list-item-active');
    var nextitem = active.next('.list-slider-item');
    console.log(nextitem.attr('class'));
    if (nextitem.attr('class') == undefined) {
        nextitem = slider.find('.list-item-1');
    }
    nextitem.show().addClass('list-item-active');
    active.hide().removeClass('list-item-active');

    setTimeout(function() {
        checkSlider(slider)
    }, 5000);
}