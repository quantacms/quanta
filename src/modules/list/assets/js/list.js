var refreshLists = function () {

    $('.list').each(function () {
        var rel = $(this).attr('rel');
        var tpl = $(this).data('tpl');
        var list = $(this);
        if (rel != undefined) {
            $(this).find('a.link').off('click').on('click', function (e) {
                list.find('.link-active').removeClass('link-active');

                if (rel == '_self') {
                    window.location.href = '/' + $(this).attr('href');
                }
                else if ($(this).attr('href') != '_empty') {
                    openAjax($(this).attr('href'), rel, undefined, tpl);
                    $(this).addClass('link-active');
                }
                e.preventDefault();
            });
        }
    });

  $('.list-sortable').each(function() {

    // Render sortable items list.
    $(this).sortable({
      update: function(e) {
        var nodes = $(this).sortable('toArray', { key: "data-node", attribute: "data-node"});
        action('{"action": {"value":"node_weight_update"}, "nodes": ' + JSON.stringify(nodes)+ '}');

      },
      start: function(e) {
      },
      stop: function(e) {
      }
    });
  });
};


$(document).bind('refresh', function () {
    refreshLists();
});
