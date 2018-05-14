/**
 * Open a node in AJAX inside another box.
 * @param name
 * @param destination
 * @param afterExec
 *   optional:
 * @param tpl
 *   optional: use another template to use to load the node.
 */
var openAjax = function(name, destination, afterExec, tpl) {
    var dest = ($('#' + destination + ' .inner').length) ? $('#' + destination + ' .inner') : $('#' + destination);
    $.ajax({
        type: "GET",
        url: name,
        dataType: "html",
        data: 'ajax=1' + ((tpl != undefined) ? ('&tpl=' + tpl) : ''),
        success: function(data) {
            var destination_obj = $('#' + destination);
            var fn = window[afterExec];
            if (typeof fn === "function") fn.apply(null);
            destination_obj.parents('.box').show();
            destination_obj.show();

            dest.html(data);
            var scrollTop = (destination_obj.offset().top - 30);
            $(document).trigger('refresh');
            $('html, body').animate({
                scrollTop: scrollTop
            }, 1000);
        }
    });
};

var refreshButtons = function() {
    $( "input.hasDatepicker").each(function() {
        var default_date = ($(this).val());
        $(this).Zebra_DatePicker({
            format: 'd-m-Y'
        });
    });
};

var action = function(dataJson) {
    $.ajax({
        type: "POST",
        dataType: 'json',
        url: '/',
        data: {json: dataJson},
        success: function(data, textStatus, jqXHR) {
            actionSuccess(data);
        },
        error: actionError
    });
};

/**
 * Successful action handler.
 * @param data
 */
var actionSuccess = function(data) {
    if (data.errors) {
        $('.messages').html(data.errors).fadeIn('slow');
        $('.shadow-submitted').removeClass('shadow-submitted');

        setTimeout(function() {
            $('.messages').fadeOut('slow');
        }, 6000);

    }
    else if (data.redirect == undefined) {
        alert("There was an error with your submission.");
    } else {
        top.location.href = data.redirect;
    }
};

/**
 * Wrong action handler.
 * @param err
 * @param exception
 */
var actionError = function(err, exception) {
    console.log(err.responseText);
    alert(exception);
};

// Inizializza.
$(document).ready(function() {
    $(document).trigger('refresh');
});

$(document).bind('refresh', function(ev) {
    refreshButtons();
});
