var hasMultipleAttribute= true;
var files = [];
$(function () {
  if (!($('.upload-files').length)) { return; }
  
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
    add: async function (e, data) {
      var tmp_files_dir = ($('#tmp_files_dir').val());
      // Access the file input element
      var fileInputElement = data.fileInput[0];
      // Check if the file input has the 'multiple' attribute
      hasMultipleAttribute = fileInputElement.hasAttribute('multiple');
      
      var file = data.files[0];
      var resolutionAttr = fileInputElement.getAttribute('data-resolution');
      $('#resolution-error-message').hide();
      if (resolutionAttr) {
        var [minWidth, minHeight] = resolutionAttr.split('*').map(Number);

        // Create an image element to check dimensions
        var img = new Image();
        img.src = URL.createObjectURL(file);
        elementContext = $(this);
        img.onload = function () {
          console.log(img.width + " * " + img.height);
          if (img.width < minWidth || img.height < minHeight) {
            $('#resolution-error-message').show();
            return;
          }          
          // If resolution is valid, proceed to handle the file upload
          handleFileUpload(data, tmp_files_dir, hasMultipleAttribute, elementContext);
        };
      } else {
        handleFileUpload(data, tmp_files_dir, hasMultipleAttribute, $(this));
      }
    },

    progress: function (e, data) {
      var progress = parseInt(data.loaded / data.total * 100, 10);
      var red = 200 - (progress * 2);
      var green = (progress * 2);
      data.context.find('input').val(progress).css('width', progress + '%').css('background', 'rgb(' + red + ',' + green + ',0)').change();

      if (progress == 100) {
        data.context.removeClass('working');
        data.context.find('.progress-wrapper').hide();
        $(document).trigger('refresh');
      }
    },

    fail: function (e, data) {
      data.context.addClass('error');
    }

  });

  function handleFileUpload(data, tmp_files_dir, hasMultipleAttribute, elementContext) {
    // TODO: should use a normal QTAG.
    var tpl = $('' +
      '<li class="working file-list-item list-item-file_admin">' +
      '<span class="file-link-item">' +
      '<span class="file-preview"></span>' +
      '<a class="file-link" target="_blank" data-filenew="true" data-filename="' + (data.files[0].name) + '" href="/tmp/' + tmp_files_dir + '/' + (data.files[0].name) + '">' + (data.files[0].name) + "</a>" +
      '</span>' +
      '<span class="progress-wrapper">' +
      '<input type="text" value="0" data-width="20" data-height="20" />' +
      '</span>' +
      '<span class="file-qtag"></span>' +
      '</li>');

    var ul = $(elementContext).closest('.shadow-content').find('ul.list');

    if (!hasMultipleAttribute) {
      ul.children().hide();
    }
    data.context = tpl.appendTo(ul);
    tpl.find('input').knob();

    tpl.find('.progress').click(function () {
      if (tpl.hasClass('working')) {
        jqXHR.abort();
      }
      tpl.fadeOut(function () {
        tpl.remove();
      });
    });

    if (data.files?.length) {
      files.push(data.files[data.files.length - 1]);
    }

    var jqXHR = data.submit();
  }

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
var refreshFileActions = function (fileElement, justView = false) {
  var filename = fileElement.find('.file-link').data('filename');
  var formname = fileElement.closest('.shadow-content').find('form').attr('id');
  var inputFileInsideForm = fileElement.closest('.shadow-content').find('form').find('input[type="file"]');
  // Check if inputFileInsideForm has the 'multiple' attribute
  hasMultipleAttribute= inputFileInsideForm.attr('multiple') !== undefined;
 
  if (fileElement.find('.file-preview').length) {
    fileElement.prepend('<input type="hidden" class="file-name" name="uploaded_file' + '-' + formname + '-' + filename + '" value="' + filename + '" >');
  }
  if(!justView){
     /**
   * Open manage file settings form on mouse enter.
   */
  fileElement.on('mouseenter', function () {
    if ($(this).hasClass('is-editing')) {
      return;
    }
    $(this).addClass('is-editing');

    // Create file actions.
    if (!$(this).find('.file-actions').length) {
      var actionsButtons= '<div class="file-actions">';
      if(hasMultipleAttribute && inputFileInsideForm.attr('thumbnail') !== 'false'){
        actionsButtons += '<input type="button" class="set-thumbnail" data-filename="' + filename + '" value="" />';
      }
      actionsButtons += '<input type="button" class="delete-file" value="delete file" />' +
      '</div>';
      // Append file actions to manage files.
      $(this).append(actionsButtons);
      
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
  }

};


var refreshThumbnail = function () {

  var thumb_href = $('#edit_thumbnail').val();

  $('.set-thumbnail').val('set as thumbnail');
  $('.selected-thumbnail').removeClass('selected-thumbnail');
  $('a[data-filename="' + thumb_href + '"]').addClass('selected-thumbnail').closest('.list-item-file_admin').find('.set-thumbnail').val('unset as thumbnail').addClass('selected-thumbnail');
}


$(document).bind('refresh', function () {
  $('.list-item-file_admin').each(function () {
        refreshFileActions($(this),$(this).parent().hasClass('just-view'));
        refreshThumbnail();
    
});

  $('.list-file_admin').each(function () {
    $(this).sortable({
      handle: '.sort-handle',
      update: function (e) {
      },
      start: function (e) {
      },
      stop: function (e) {
      }
    });
  });

  var node_name = $('#edit_path').val();
  var tmp_files_dir = ($('#tmp_files_dir').val());

  $('.file-preview').each(function () {
    var filelink = $(this).parent().find('.file-link');
    var filename = filelink.data('filename');
    var tag_attr = (filelink.data('filenew') != undefined) ? ('tmp_path=' + tmp_files_dir) : ('node=' + node_name);
    var qtag = '/qtag/[FILE_PREVIEW|' + tag_attr + ':' + encodeURIComponent(filename) + ']';
    $(this).load(qtag);
  });

  //TODO: I don't know what the purpose of [FILE_QTAG_SUGGESTION|] is It only displays incorrect data, but the name is displayed correctly elsewhere
  //TODO: remove this 
  // $('.file-qtag').each(function () {
  //   var filelink = $(this).parent().find('.file-link');
  //   var filename = filelink.data('filename');
  //   var tag_attr = (filelink.data('filenew') != undefined) ? ('tmp_path=' + tmp_files_dir) : ('node=' + node_name);
  //   var qtag_suggestion = '/qtag/[FILE_QTAG_SUGGESTION|' + tag_attr + ':' + encodeURIComponent(filename) + ']';
  //   $(this).load(qtag_suggestion);
  // });
});

$(document).bind('shadow_save', function () {
  $('.progressBar').each(function () {
    if ($(this).val() != 100) {
      shadowConfirmClose = confirm('Upload of files still in progress. Are you sure you want to save?');
    }
  });
  if(files.length){
     // Dispatch a custom event when the form submission
    var event = new CustomEvent('fileSubmission', {
      detail: {
          files: files
      }
    });
    document.dispatchEvent(event);
  }
});
