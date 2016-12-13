$(document).ready(function() {
  $('body').append('<div id="tooltip"></div>');				
});

$(document).bind('refresh', function() {
    $('[tooltip]').on('mouseover', function(e) {

        $('#tooltip').html($(this).attr('tooltip')).show();
        var tooltip = $('#tooltip');
        $(document).off('mousemove').on('mousemove', function(e) {
            var tipLeft = e.pageX > ($(window).width() / 2) ? (e.pageX - tooltip.innerWidth()) : (e.pageX + 10);
            var tipTop = e.pageY > ($(window).height() / 2) ? (e.pageY - + tooltip.innerHeight()) : (e.pageY + 10);
            $('#tooltip').css({
                left:  tipLeft,
                top:   tipTop
            });
        });
    });
    $('[tooltip]').on('mouseout', function(e) {
        $('#tooltip').unbind();
        $('#tooltip').hide();
    });
});
