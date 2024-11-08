var refreshJumpers = function() {
    $('.jumper').each(function() {
        $(this).bind('change', function() {
            var rel = $(this).attr('rel');
            var tpl = $(this).data('tpl');

            var method = $(this).data('jumper-method');
            var field = $(this).data('field');

          if (method == 'querystring') {
                const url = new URL(window.location.href);                
                url.searchParams.set(field, $(this).val());
                window.location.href = url.toString();
          }
          else if (method == 'nothing') {
              // Used for empty jumpers.
          }
          else {
          if (rel == '_self') {
                window.location.href = '/' + $(this).val();
            }
            else if ($(this).val() != '_empty') {
                openAjax('/' + $(this).val(), rel, 'refreshJumpers', tpl);
            }
          }
        });

    });
};

$(document).bind('refresh', function() {
    refreshJumpers();
});
