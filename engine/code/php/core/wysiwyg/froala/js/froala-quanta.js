$(document).bind('shadow_node_add',function(ev) { froala(); });
$(document).bind('shadow_node_edit',function(ev) { froala(); });

function froala() {
    $('#edit_content').froalaEditor();
    $('div[style*="z-index: 9999"]').hide();
}

$(document).bind('keyup', function() {
    $('div[style*="z-index: 9999"]').hide();
});