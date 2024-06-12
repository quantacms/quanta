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
  if (typeof data !== 'object') {
    alert("There was an error with your submission.");
    console.log(data);
    return false;
  }

  // TODO: better way to display errors.
  if (data.errors) {
    $('.messages').html(data.errors).fadeIn('slow');
    $('.shadow-submitted').removeClass('shadow-submitted');

    setTimeout(function() {
      $('.messages').fadeOut('slow');
    }, 6000);
  }
  if (data.redirect != undefined) {
    top.location.href = data.redirect;
  }
  return true;

};

/**
 * Wrong action handler.
 * @param err
 * @param exception
 */
var actionError = function(err, exception) {
  if(err?.responseJSON?.shadowErrors){
    var errors = JSON.parse(err.responseJSON.shadowErrors);
    $('.shadow-submitted').removeClass('shadow-submitted');
    $('#shadow-outside').find('input, textarea, select').each(function () {
      var inputField = $(this);
      var fieldWrapper = $(this).closest('.form-item-wrapper');
      
      var fieldName = inputField.attr('name');
              
      // Check if the field is required, empty, and visible
      if (errors[fieldName]) {
        
        // Add error message to the field wrapper
        fieldWrapper.addClass('has-validation-errors');
        if (fieldWrapper.find('.validation-error').length === 0) {
          fieldWrapper.prepend(`<div class="validation-error">${errors[fieldName]}</div>`);
        }
      }
      else{
        // Remove error styling and message if field is not empty and visible
        fieldWrapper.removeClass('has-validation-errors');
        fieldWrapper.find('.validation-error').remove();
      }
  
    });  
  }
  else{
    alert(exception);
  }
  // Stop form submission if there are empty required fields
  $('.shadow-submit').removeClass('shadow-submitted'); // Remove shadow-submitted class
};

var quanta_html_escape = function(str) {
return str.replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
};


// Inizializza.
$(document).ready(function() {
    $(document).trigger('refresh');
});

