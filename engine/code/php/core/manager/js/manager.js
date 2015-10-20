
function refreshManagerLeaves() {
    $('.manager-leaf').each(function() {
        $(this).unbind().bind('click', function() {

            var leaf = ($(this).attr('rel')).replace('leaf-', '');
            var leafobj = $(this);
            leafobj.parent().find('ul').remove();
            var expanded = $(this).parent().hasClass('expanded');
            if (expanded) {
                leafobj.parent().removeClass('expanded');
            } else {
                var openurl = '/expand/?path=' + leaf + '&node=sabaudia';
                $.ajax({
                    url: openurl
                }).done(function(data) {
                    leafobj.parent().append(data);
                    leafobj.parent().addClass('expanded');
                });
            }
            return FALSE;
        });
    });
}

$(document).ready(function() {
    $('.open-manager').on('click', function() {
        openShadow({ module : 'manager', context: 'manager', type: 'single'});

    });
    refreshManagerLeaves();
});

