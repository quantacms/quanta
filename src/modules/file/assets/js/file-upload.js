$(function () {

  $('.drop a').click(function () {
    // Simulate a click on the file input button
    // to show the file browser dialog
    $(this).parent().find('input').click();
  });

  // Initialize the jQuery File Upload plugin
  $('.upload-files').fileupload({

    // This element will accept file drag/drop uploading
    dropZone: $(this).find('.drop'),

    // This function is called when a file is added to the queue;
    // either via the browse button, or via drag/drop:
    add: function (e, data) {

      var tmp_files_dir = ($('#tmp_files_dir').val());

      console.log(data.paramName);
      var form_name = data.paramName;

      // TODO: should use a normal QTAG.
      var tpl = $('' +
        '<li class="working file-list-item list-item-file_admin">' +
        '<span class="sort-handle"></span>' +
        '<span class="file-link-item">' +
        '<span class="file-preview"></span>' +
        '<a class="file-link" target="_blank" data-filenew="true" data-filename="' + (data.files[0].name) + '" href="/tmp/' + tmp_files_dir + '/' + (data.files[0].name) + '">' + (data.files[0].name) + "</a>" +
        '</span>' +
        '<span class="progress-wrapper">' +
        '<span class="progress"></span>' +
        '<input type="text" value="0" data-width="20" data-height="20" />' +
        '</span>' +
        '<span class="file-qtag"></span>' +

        '</li>');


      var ul = $(this).closest('.shadow-content').find('ul');

      // Add the HTML to the UL element
      data.context = tpl.appendTo(ul);

      // Initialize the knob plugin
      tpl.find('input').knob();

      // Listen for clicks on the cancel icon
      tpl.find('.progress').click(function () {

        if (tpl.hasClass('working')) {
          jqXHR.abort();
        }

        tpl.fadeOut(function () {
          tpl.remove();
        });

      });

      // Automatically upload the file once it is added to the queue
      var jqXHR = data.submit();
    },

    progress: function (e, data) {

      // Calculate the completion percentage of the upload
      var progress = parseInt(data.loaded / data.total * 100, 10);

      // Update the hidden input field and trigger a change
      // so that the jQuery knob plugin knows to update the dial
      var red = 200 - (progress * 2);
      var green = (progress * 2);
      data.context.find('input').val(progress).css('width', progress + '%').css('background', 'rgb(' + red + ',' + green + ',0)').change();

      if (progress == 100) {
        data.context.removeClass('working');
        //data.context.find('input').fadeOut('slow');
        $(document).trigger('refresh');
      }
    },

    fail: function (e, data) {
      // Something has gone wrong!
      data.context.addClass('error');
    }

  });


  // Prevent the default action when a file is dropped on the window
  $(document).on('drop dragover', function (e) {
    e.preventDefault();
  });

  // Helper function that formats the file sizes
  function formatFileSize(bytes) {
    if (typeof bytes !== 'number') {
      return '';
    }

    if (bytes >= 1000000000) {
      return (bytes / 1000000000).toFixed(2) + ' GB';
    }

    if (bytes >= 1000000) {
      return (bytes / 1000000).toFixed(2) + ' MB';
    }
    return (bytes / 1000).toFixed(2) + ' KB';
  }

});


// Initialize button events for file table admin.
var refreshFileActions = function (fileElement) {
  var filename = fileElement.find('.file-link').data('filename');
  var formname = fileElement.closest('.shadow-content').find('form').attr('id');
  if (fileElement.find('.file-preview').length) {
    fileElement.prepend('<input type="hidden" class="file-name" name="uploaded_file' + '-' + formname + '-' + filename + '" value="' + filename + '" >');
  }

  /**
   * Open manage file settings form on mouse enter.
   */
  fileElement.on('mouseenter', function() {
    if ($(this).hasClass('is-editing')) {
      return;
    }
    $(this).addClass('is-editing');

    // Create file actions.
    if (!$(this).find('.file-actions').length) {
      // Append file actions to manage files.
      $(this).append('<div class="file-actions">' +
        '<input type="button" class="set-thumbnail" data-filename="' + filename + '" value="" />' +
        '<input type="button" class="delete-file" value="delete file" />' +
        '</div>'
      );
    }

    // Initialize set thumbnail buttons.
    $('.set-thumbnail').on('click', function () {
      if (!($(this).hasClass('selected-thumbnail'))) {
        $('#edit_thumbnail').val($(this).data('filename'));
      }
      else {
        $('#edit_thumbnail').val('');
      }
      refreshThumbnail();
      return false;
    });

    // Initialize file delete buttons.
    $('.delete-file').on('click', function () {
      var filepath = $(this).parents('li').find('.file-link').data('filename');
      var parent = $(this).closest('li');
      if (confirm('Are you sure you want to delete this file? \n' + filepath)) {
        var node_name = ($(this).closest('.list').data('node'));

        $.ajax({
          url: "/" + node_name + "/?file_delete=" + filepath,
          success: function () {
            parent.fadeOut('slow');
          },
          error: function () {
            alert("Error while deleting file. Aborting.");
          }
        });
      }
      return false;
    });

    refreshThumbnail();
  }).on('mouseleave', function () {
    $(this).removeClass('is-editing');
    $(this).find('.file-actions').remove();
  });

};


var refreshThumbnail = function() {

  var thumb_href = $('#edit_thumbnail').val();

  $('.set-thumbnail').val('set as thumbnail');
  $('.selected-thumbnail').removeClass('selected-thumbnail');
  $('a[data-filename="' + thumb_href + '"]').addClass('selected-thumbnail').closest('.list-item-file_admin').find('.set-thumbnail').val('unset as thumbnail').addClass('selected-thumbnail');
}


$(document).bind('refresh', function () {
  $('.list-item-file_admin').each(function () {
    refreshFileActions($(this));
    refreshThumbnail();
  });

  $('.list-file_admin').each(function() {
    $(this).sortable({
      handle: '.sort-handle',
      update: function(e) {
      },
      start: function(e) {
      },
      stop: function(e) {
      }
    });
  });

  var node_name = $('#edit_path').val();
  var tmp_files_dir = ($('#tmp_files_dir').val());

  $('.file-preview').each(function() {
    var filelink = $(this).parent().find('.file-link');
    var filename = filelink.data('filename');
    var tag_attr = (filelink.data('filenew') != undefined) ? ('tmp_path=' + tmp_files_dir) : ('node=' + node_name);
    var qtag ='/qtag/[FILE_PREVIEW|' + tag_attr + ':' + encodeURIComponent(filename) + ']';
    $(this).load(qtag);
  });

  $('.file-qtag').each(function() {
    var filelink = $(this).parent().find('.file-link');
    var filename = filelink.data('filename');
    var tag_attr = (filelink.data('filenew') != undefined) ? ('tmp_path=' + tmp_files_dir) : ('node=' + node_name);
    var qtag_suggestion ='/qtag/[FILE_QTAG_SUGGESTION|' + tag_attr + ':' + encodeURIComponent(filename) +']';
    $(this).load(qtag_suggestion);
  });
});

$(document).bind('shadow_save', function () {
  $('.progressBar').each(function() {
    if ($(this).val() != 100) {
      shadowConfirmClose = confirm('Upload of files still in progress. Are you sure you want to save?');
    }
  })
});