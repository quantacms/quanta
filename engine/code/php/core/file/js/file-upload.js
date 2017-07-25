$(function(){
    var ul = $('#filelist ul');

    $('#drop a').click(function(){
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

            var tpl = $('<li class="working list-item list-item-file file-' + tfile[1] + '"><span class="filename"></span><span class="progress-wrapper"><span class="progress"></span><input type="text" value="0" data-width="20" data-height="20"'+
                ' data-fgColor="#0788a5" data-readOnly="1" data-bgColor="#3e4043" /></span></li>');

            // Append the file name and file size
            tpl.find('.filename').html('<a class="file-link" href="' + data.files[0].name + '">' + data.files[0].name + "</a>").append('<i>(' + formatFileSize(data.files[0].size) + ')</i>');


            // Add the HTML to the UL element
            data.context = tpl.appendTo(ul);

            // Initialize the knob plugin
            tpl.find('input').knob();

            // Listen for clicks on the cancel icon
            tpl.find('.progress').click(function(){

                if(tpl.hasClass('working')){
                    jqXHR.abort();
                }

                tpl.fadeOut(function(){
                    tpl.remove();
                });

            });

            // Automatically upload the file once it is added to the queue
            var jqXHR = data.submit();
        },

        progress: function(e, data){

            // Calculate the completion percentage of the upload
            var progress = parseInt(data.loaded / data.total * 100, 10);

            // Update the hidden input field and trigger a change
            // so that the jQuery knob plugin knows to update the dial
            data.context.find('input').val(progress).change();

            if(progress == 100){
                data.context.removeClass('working');
                $(document).trigger('refresh');
            }
        },

        fail:function(e, data){
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
var refreshFileActions = function() {

    // Initialize file delete buttons.
    $('.delete-file').on('click', function() {
        var filepath = $(this).parents('li').find('.file-link').attr('href');
        var parent = $(this).closest('li');
        if (confirm('Are you sure you want to delete this file? \n' + filepath)) {
            var node_name = ($(this).closest('.list').data('node'));
            $.ajax({
                url: "/" + node_name + "/?file_delete=" + filepath,
                success: function() {
                    parent.fadeOut('slow');
                },
                error: function() {
                    alert("ERROR in deleting file.");
                }
            });
        }
        return false;
    });

    // Initialize set thumbnail buttons.
    $('.set-thumbnail').on('click', function() {
        var filepath = $(this).parents('li').find('.file-link').attr('href');
        $('#edit_thumbnail').val(filepath);
        $('.selected-thumbnail').removeClass('selected-thumbnail');
        $(this).toggleClass('selected-thumbnail');
        $('.show-thumbnail').html('<img src="' + filepath + '" />');
        return false;
    })
    var thumb_href = $('#edit_thumbnail').val();
    $('a[href="' + thumb_href + '"]').addClass('selected-thumbnail');


}

$(document).bind('refresh', function() {
    var thumb_href = $('#edit_thumbnail').val();

    $('#filelist .list-item-file').on('mouseenter', function() {
       $(this).find('.file-actions').remove();
        // TODO: how to create a full path?
        var href = ($(this).find('.file-link').attr('href'));

        var selectedThumbnail = (href == thumb_href) ? 'selected-thumbnail' : '';

        var tagType = $(this).find('.file-link-item').hasClass('file-image') ? 'IMG' : 'FILE';

       $(this).append('<span class="file-actions"><input type="text" value="[' + tagType + ':' + href + ']" /><a class="delete-file" href="#">x</a><a class="set-thumbnail ' + selectedThumbnail + '" href="#">&#9786;</a></span>');

       refreshFileActions();
    }).on('mouseleave', function() {
        $(this).find('.file-actions').remove();
    });

    refreshFileActions();
});