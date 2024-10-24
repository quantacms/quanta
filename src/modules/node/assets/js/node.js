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
                node: $(this).attr('data-rel'),
                redirect: $(this).data('redirect')
            };

      if ($(this).data('language') != undefined) {
        shadow.language = $(this).attr('data-language');
      }

      openShadow(shadow);

      e.preventDefault();
        });

     // Duplicate Node link behavior.
     $('.duplicate-link').off('click').on('click', function(e) {
        var components = (($(this).attr('data-components') != undefined) ? ($(this).attr('data-components').split(',')) : ['node_edit', 'node_metadata', 'node_status', 'file_form', 'node_form']);

        var shadow = {
          module : 'node',
          context: 'node_duplicate',
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
$(document).bind('shadow_open', function() {
	initImgOperationsModal();
	handleImgOperation('set_as_thumbnail');
	handleImgOperation('delete_img');
});

$(document).ready(function() {	
  $('.file-operation').click(function (e) {
		e.preventDefault();
		openShadow({
			module: 'file',
			context: 'preview_img',
			widget: 'single',
			components: ['preview_img'],
			img_node: $(this).data('img_node'),
			img_key: $(this).data('img_key'),
			img: $(this).data('img'),
		});	
	  });
});
  function initImgOperationsModal(){
    if ($('#delete_img').length > 0) {
      $('#set_as_thumbnail').addClass('not-submittable');
      $('#delete_img').addClass('not-submittable');
          $('#cancel').hide();
      }
  }
  
  function handleImgOperation(button){
    $(`#${button}`).click(function (e) {
      e.preventDefault();
      $(this).addClass('shadow-submitted');
      var $form = $('#file_operations_form');
      // Find the hidden input with the name 'action_type'
      var $input = $form.find('input[name="action_type"]');
      if ($input.length === 0) {
        // If the input doesn't exist, create and append it to the form
        $('<input>').attr({
          type: 'hidden',
          name: 'action_type',
          value: button
        }).appendTo($form);
      } else {
        // If the input exists, just update its value
        $input.val(button);
      }
      // Call the submitFormViaAjax function to handle form submission
      submitFormViaAjax(e,$form);
    });
  }

document.addEventListener('formSubmissionSuccess', function(event) {
    if(formId == "#file_operations_form"){
      const response = JSON.parse(event.detail.response);
      console.log(response);
      if(response.success){
        closeShadow();
        // Find the image by its src attribute and fade it out
        const imgSrc = response.img;
        switch (response.action_type) {
          case "delete_img":
            $(`img[src$="${imgSrc}"]`).each(function() {
              const $parent = $(this).parent();
              
              if ($parent.is('div')) {
                $parent.fadeOut(1000, function() {
                  $(this).remove();
                });
              } else {
                $(this).fadeOut(1000, function() {
                  $(this).remove();
                });
              }
            });
            break;
            
          case "set_as_thumbnail":
           // Remove the "is-thumbnail" class from all other images
           $('.is-thumbnail').removeClass('is-thumbnail');
  
           // Add "is-thumbnail" class to the target image's parent div with an animation
           const $target = $(`img[src$="${imgSrc}"]`).closest('.preview-item');
           
           $target.css({ transform: 'scale(1.1)', opacity: 0 })
               .addClass('is-thumbnail')
               .animate({ opacity: 1 }, 500)
               .css({ transform: 'scale(1.0)' });
            break;
          default:
            break;
        }   
      }
    }
});
