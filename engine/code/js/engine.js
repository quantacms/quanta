// Inizializza.
$('document').ready(function() {
    pageRefresh();
    refreshButtons();

});

String.prototype.bookify = function() {
    return this.replace('---', '<div style="page-break-after: always;">&nbsp;</div>')
}

var refreshButtons = function() {
    $('.delete-file').unbind().on('click', function() {
        var file_to_delete = $(this).parent().find('.file-link').attr('href');
        var parent = $(this).closest('li');
        if (confirm('Are you sure you want to delete this file? \n' + file_to_delete)) {
            $.ajax({
                url: "?file_delete=" + file_to_delete,
                success: function() {
                    parent.fadeOut('slow');

                },
                error: function() {
                    alert("ERROR");
                }
                });
        }
        return false;
    });

    $('.set-thumbnail').bind('click', function() {
        $('#edit-thumbnail').attr('value', $(this).attr('rel'));
        $('.selected-thumbnail').removeClass('selected-thumbnail');
        $(this).addClass('selected-thumbnail');
        $('.show-thumbnail').html('<img src="' + $(this).attr('rel') + '" />');
        return false;
    })

    // Open page when clicking abstract
    $('.abstract').bind('click', function() {
        var a = $(this).parent().find('a');
        window.location.href = '/' + a.attr('href');
    });

    $('.delete-link').bind('click', function() {
        pageDelete();
    });

    $('.edit-link').bind('click', function() {
        pageEdit('node_edit');
    });

    $('.add-link').bind('click', function() {
        pageEdit('node_add');
    });

    $( "input.hasDatepicker").each(function() {
        var default_date = ($(this).val());
        $(this).Zebra_DatePicker({
            format: 'd-m-Y'
        });
    });
}

// Refresh the page after AJAX loading.
var pageRefresh = function() {
    $('.comment-mail-link').attr('href', $('.comment-mail-link').attr('href') + '?subject=[' + $('title').text() + '] Comment on: ' + $('h1').text() + '&body===========%0D%0DWrite your comment here. It will be moderated and published%0D%0D==========');
}

var action = function(dataJson) {

    $.ajax({
        type: "POST",
        dataType: 'json',
        url: '/',
        data: {json: dataJson},
        success: actionSuccess,
        error: actionError
    });
}

var actionSuccess = function(data) {
    if (data.errors) {
        $('.messages').html(data.errors).fadeIn('slow');
        setTimeout(function() {
            $('.messages').fadeOut('slow');
        }, 6000);

    }
    else if (data.redirect == undefined) {
        console.log(data);
        alert("There was an error with your submission.");
    } else {
        top.location.href = data.redirect;
    }
}

var actionError = function(err, exception) {
    console.log(err.responseText);
    alert(exception);
}