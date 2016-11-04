// Inizializza.
$(document).ready(function() {
    $(document).trigger('refresh');
});

$(document).bind('refresh', function(ev) {
    refreshButtons();
});


String.prototype.bookify = function() {
    return this.replace('---', '<div style="page-break-after: always;">&nbsp;</div>')
}

var openAjax = function(name, destination, afterExec) {
    $('#' + destination).load(name + '/?ajax', function() {
        var fn = window[afterExec];
        if (typeof fn === "function") fn.apply(null);
        // TODO: should not be here! We need a JS hooking system.
        resizeBoxes();
        refreshButtons();
        $('#' + destination).parents('.box').show();
        $('html, body').animate({
            scrollTop: ($("#" + destination).offset().top - 30)
        }, 1000);
    });
}

var refreshButtons = function() {

    $('ul[rel]').each(function() {
       var rel = $(this).attr('rel');
       $(this).find('a').on('click', function() {
           openAjax($(this).attr('href'), rel);
           return false;
       });
    });

    // Open page when clicking abstract
    $('.abstract').on('click', function() {
        var a = $(this).parent().find('a');
        window.location.href = '/' + a.attr('href');
    });

    $( "input.hasDatepicker").each(function() {
        var default_date = ($(this).val());
        $(this).Zebra_DatePicker({
            format: 'd-m-Y'
        });
    });
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

    } else if (data.refresh != undefined) {
      alert('ajax refresh go!!!');
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