let isDragging = false; // Add a flag to track dragging state
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
    $('.file-sortable').each(function () {
      // Render sortable items list.
      $(this).sortable({
        update: async function (e) {
          // Get all `.file-operation` divs in the sortable container
          const operations = $(this).find('.file-operation');
    
          // Extract `data-img` values into an array
          const files = operations.map(function () {
            return $(this).attr('data-img'); // Get `data-img` from `.file-operation`
          }).get(); // Convert to standard array
    
          // Extract the `data-img_node` value from the first `.file-operation` (assuming all are the same)
          const nodeName = operations.first().attr('data-img_node');
    
          // AJAX request
          await $.ajax({
            type: "POST",
            dataType: 'json',
            url: '/',
            data: {
              json: JSON.stringify({
                action: { value: "file_weight_update" },
                files: { value: JSON.stringify(files) },
                node_name: { value: nodeName }
              })
            }
          });
        },
        start: function (e) {
          isDragging = true; // Set flag to true when dragging starts
        },
        stop: function (e) {
          setTimeout(() => {
            isDragging = false; // Reset flag when dragging stops
          }, 500);
        },
      });
    });  
});
