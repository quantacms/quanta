
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
                var openurl = '/expand/?path=' + leaf + '&node=' + $('#edit_path').val();
                $.ajax({
                    url: openurl
                }).done(function(data) {
                    leafobj.parent().append(data);
                    leafobj.parent().addClass('expanded');
                });
            }
            return false;
        });
    });

    $('.manager-tree').find('input').each(function() {
       $(this).unbind().bind('click', function() {
         if ($(this).prop('checked')) {
             $('input[name=rem-' + $(this).attr('name') + ']').remove();
             $('.manager-tree').append('<input type="hidden" name="add-' + $(this).attr('name') + '" value="add-' + $(this).val() + '" />');
         } else {
             $('input[name=add-' + $(this).attr('name')+']').remove();
             $('.manager-tree').append('<input type="hidden" name="rem-' + $(this).attr('name') + '" value="remove-' + $(this).val() + '" />');
         }
       });
    });
};

$(document).ready(function() {
    $('.open-manager').on('click', function() {
        openShadow({ module : 'manager', context: 'manager', type: 'single'});

    });

    refreshManagerLeaves();
});

