$(document).bind('refresh', function() {
  $('.category-toggle').on('click', function(e) {

    var node = $(this).data('node');
    var add_title = $(this).data('add-title');
    var remove_title = $(this).data('remove-title');
    var category = $(this).data('category');
    var toggle_href = '/qtag/[CATEGORY_TOGGLE|add_title=' + add_title + '|remove_title=' + remove_title + '|node=' + node + '|toggle:' + category + ']';
    $(this).load(toggle_href);

    e.preventDefault();
  });

  // Delete Node link behavior.
  $('.delete-link').off('click').on('click', function(e) {
    var component = $('.delete-link').attr('data-component') ? $('.delete-link').attr('data-component') : 'node_delete';
        openShadow({
            module: 'node',
            context: 'node_delete',
            widget: 'single',
            components: [component,'node_form'],
            node: $(this).attr('data-rel'),
            redirect: $(this).data('redirect')
        });
        e.preventDefault();

    });

    // Edit Node link behavior.
    $('.edit-link').off('click').on('click', function(e) {
      // TODO: select default components in a hook.
        var components = (($(this).attr('data-components') != undefined) ? ($(this).attr('data-components').split(',')) : ['node_edit', 'node_metadata', 'node_status', 'file_form', 'node_form']);

        var shadow = {
          module : 'node',
          context: 'node_edit',
          widget: $(this).data('widget'),
          components: components,
          node: $(this).data('rel'),
          redirect: $(this).data('redirect')
        };

        // Use jQuery's data() method to get all data attributes
        $.each($(this).data(), function(key, value) {
            if (shadow[key] == undefined) {
                shadow[key] = value;
            }
        });

        openShadow(shadow);

        e.preventDefault();
    });

    // Add Node link behavior.
    $('.add-link').off('click').on('click', function(e) {
            var components = (($(this).attr('data-components') != undefined) ? ($(this).attr('data-components').split(',')) : ['node_edit', 'node_metadata', 'node_status', 'file_form', 'node_form']);
            var shadow = {
                module: 'node',
                context: 'node_add',
                widget: $(this).attr('data-widget'),
                language: $(this).attr('data-language'),
                components: components,
                node: $(this).attr('data-rel')
            };


      if ($(this).data('language') != undefined) {
        shadow.language = $(this).attr('data-language');
      }

      if ($(this).data('manager') != undefined) {
        shadow.manager = $(this).attr('data-manager');
      }
      openShadow(shadow);

      e.preventDefault();
        });


    $('.node-item-actions').parent()
        // TO BE COMPLETED
        .off('mouseenter').on('mouseenter', function() {
            $(this).parent().css('opacity', '0.8');
            $(this).children('.node-item-actions').show();
        })
        .off('mouseleave').on('mouseleave', function() {
            $(this).parent().css('opacity', '1');
            $(this).children('.node-item-actions').hide();
        });
});
