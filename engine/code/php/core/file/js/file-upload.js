$(function () {
  var ul = $('#filelist ul');

  $('#drop a').click(function () {
    // Simulate a click on the file input button
    // to show the file browser dialog
    $(this).parent().find('input').click();
  });

  // Initialize the jQuery File Upload plugin
  $('#edit-files').fileupload({

    // This element will accept file drag/drop uploading
    dropZone: $('#drop'),

    // This function is called when a file is added to the queue;
    // either via the browse button, or via drag/drop:
    add: function (e, data) {

      var tfile = data.files[0].name.split('.');

      var tpl = $('<li class="working list-item list-item-file file-' + tfile[1] + '"><span class="filename"></span><span class="progress-wrapper"><span class="progress"></span><input type="text" value="0" data-width="20" data-height="20"' +
        ' data-fgColor="#0788a5" data-readOnly="1" data-bgColor="#3e4043" /></span></li>');

      // Append the file name and file size
      tpl.find('.filename').html('<a class="file-link" href="' + data.files[0].name + '">' + data.files[0].name + "</a>").append('<i>(' + formatFileSize(data.files[0].size) + ')</i>');


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
      data.context.find('input').val(progress).change();

      if (progress == 100) {
        data.context.removeClass('working');
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
var refreshFileActions = function (hoverFileElement) {
  // The file Type.
  var tagType = hoverFileElement.find('.file-link-item').hasClass('file-image') ? 'IMG' : 'FILE';
  // The file URL.
  var href = (hoverFileElement.find('.file-link').attr('href'));
  var preview = '';

  if (tagType == 'IMG') {
    preview = '<img class="file-preview-img" src="' + href + '">';
  }

  if (!hoverFileElement.find('.file-preview').length) {
    hoverFileElement.prepend('<span class="file-preview">' + preview + '</span>');
    hoverFileElement.prepend('<input type="text" class="file-name" name="uploaded-file-' + href + '" value="' + href + '" >');
  }


  /**
   * Open manage file settings form on mouse enter.
   */
  hoverFileElement.on('mouseenter', function() {
    if ($(this).hasClass('is-editing')) {
      return;
    }
    $(this).addClass('is-editing');
    // Create file actions.
    if (!$(this).find('.file-actions').length) {
      // Append file actions to manage files.
      $(this).append('<div class="file-actions">' +
        '<input type="text" value="[' + tagType + ':' + href + ']" />' +
        '<input type="button" class="set-thumbnail" data-href="' + href + '" value="" />' +
        '<input type="button" class="delete-file" value="delete file" />' +
        '</div>'
      );
    }

    // Initialize set thumbnail buttons.
    $('.set-thumbnail').on('click', function () {
      if (!($(this).hasClass('selected-thumbnail'))) {
        $('.selected-thumbnail').removeClass('selected-thumbnail');
        $('#edit_thumbnail').val($(this).data('href'));
      }
      else {
        $('#edit_thumbnail').val('');
      }
      refreshThumbnail();
      return false;
    });

    // Initialize file delete buttons.
    $('.delete-file').on('click', function () {
      var filepath = $(this).parents('li').find('.file-link').attr('href');
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
  $('a[href="' + thumb_href + '"]').addClass('selected-thumbnail').closest('.list-item-file').find('.set-thumbnail').val('unset as thumbnail').addClass('selected-thumbnail');
}


$(document).bind('refresh', function () {
  $('.list-item-file_admin').each(function () {
    refreshFileActions($(this));
    refreshThumbnail();
  });

  $('.list-file_admin').each(function() {
    $(this).sortable({
      update: function() {
        // ...
      }
    });
  });

});



