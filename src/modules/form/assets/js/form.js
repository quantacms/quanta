var multipleCounters = 0;

/**
 * Refresh all forms.
 */
var refreshForms = function () {
    $('*[data-multiple]').each(function () {
        var inputItem = $(this);
        refreshMultiple(inputItem);
    });
    refreshAutocomplete();
    refreshPasswordFields();
};

/**
 * button to show password
 */
var refreshPasswordFields = function() {
  // Aggiungi l'icona "occhio" accanto ai campi password
  $('.form-item-password').each(function() {
    const $this = $(this);
    $this.after('<span class="toggle-password"><i class="icon-eye_outline"></i></span>');
  });

  // Gestisci il click sull'icona per mostrare/nascondere la password
  $(".toggle-password").click(function() {
    const $password = $(this).prev('.form-item-password');
    const type = $password.attr('type') === 'password' ? 'text' : 'password';
    $password.attr('type', type);

    // Toggle the eye / eye slash icon
    $(this).find('i').toggleClass('icon-eye-slash_outline');
  });
};

/**
 * Refresh autocomplete fields.
 */
var refreshAutocomplete = function() {

  $("input.autocomplete").each(function() {
    var node = $(this).data('node');
    var listFilter = $(this).data('list-filter');
    var options = {
      url: function(phrase) {
        var autocomplete_path = "/autocomplete?search_string=" + phrase + "&search_node=" + node + "&list_filter=" + listFilter;
        return autocomplete_path;
      },
      getValue: "name",
      placeholder: "",
      template: {
        type: "description",
        fields: {
          description: "title"
        }
      },

      list: {
        match: {
          enabled: true
        },
        maxNumberOfElements: 15,
        showAnimation: {
          type: "slide",
          time: 50
        },
        hideAnimation: {
          type: "slide",
          time: 50
        }
      },
      theme: "round"
    };

    $(this).easyAutocomplete(options);
  });
};

/**
 * Refresh multiple fields.
 * @param inputItem
 */
var refreshMultiple = function (inputItem) {
  var wrapper = inputItem.closest('.form-item-multiple-wrapper');
  var inputItemID = inputItem.attr('id');
  var inputItemName = inputItem.attr('name');
  var inputCounter = 0;
  var limit = inputItem.data('limit');
  var totItems = $('*[name=' + inputItemName + ']').length;
  // REMOVE button.
  $('*[name=' + inputItemName + ']').each(function() {
    inputCounter++;
      var form_item_remove_id = 'form-item-remove-' + $(this).attr('id');

    if (!($('#' + form_item_remove_id).length)) {

      $(this).after('<input type="button" rel="' + $(this).attr('id') + '" id="' + form_item_remove_id + '" class="form-item-remove" value="-" />');
        $('#' + form_item_remove_id).unbind().bind('click', function () {
          $(this).closest('.form-item-multiple-wrapper').remove();
          refreshMultiple(inputItem);
          refreshAutocomplete();
        });
      }
  });

  // Remove - button if only one element present.
  if (inputCounter < 2) {
    wrapper.find('.form-item-remove').remove();
  }
  // Preparing the + button. Remove the existing one...
  var form_item_add_id = 'form-item-add-' + inputItemName;
  var last_item = $('*[name=' + inputItemName + ']').last();
  $('#' + form_item_add_id).remove();

  // Add the + button... only if limit not reached.
  if ((limit == undefined) || !limit || (totItems < limit)) {
    last_item.after('<input type="button" value="+" rel="' + inputItemID + '" id="' + form_item_add_id + '" class="form-item-add">');
    $('#' + form_item_add_id).unbind().bind('click', function () {
      multipleCounters++;
      var last_item = $('*[name=' + inputItemName + ']').last();
      var new_id = inputItemName + '_' + multipleCounters;
      var newFormItem = last_item.clone().attr('id', new_id).attr('value', '');
      last_item.closest('.form-item-multiple-wrapper').after('<div class="form-item-multiple-wrapper">' + newFormItem.prop('outerHTML') + '</div>');
      refreshMultiple(inputItem);
      refreshAutocomplete();
    });
    }

};

$(document).bind('refresh', function () {
    refreshForms();
});

$(document).bind('shadow_open', function() {
  $('input[type=text],input[type=password]').keyup(function (e) {
    if (e.keyCode === 13) {
      submitShadow();
    }
  });
});

$(document).ready(function() {

  // Attach the submit handler to the form
  $('.ajxa-form').on('submit', function(event) {
      submitFormViaAjax(event, this);
  });

// Handle change event for radio buttons
$('.stars-rating input').on('change', function() {
    fillStars($(this));
});
    // Handle click event for labels
    $('.stars-rating label').on('click', function() {
      var $input = $(this).prev('input');
      $input.prop('checked', true).trigger('change');
  });

// Initialize star ratings based on checked input
$('.stars-rating input:checked').each(function() {
    fillStars($(this));
});

 });

 // Function to handle star filling
 function fillStars($element) {
  var $parent = $element.closest('.stars-rating');
  var selectedValue = $element.val();

  $parent.find('label').each(function() {
      var $label = $(this);
      var labelValue = $label.prev('input').val();

      if (labelValue <= selectedValue) {
          $label.css('color', '#f5b301');
      } else {
          $label.css('color', '#ccc');
      }
  });
}

function submitFormViaAjax(e,form) {
  e.preventDefault(); // Prevent the default form submission
  formId = `#${$(form).attr('id')}`;
  // Serialize form data
  var formData = $(formId).serialize();
  $(formId).css('opacity', '0.5');
  var submitButton = $(formId).find('input[type="submit"]');

  // Add the 'shadow-submitted' class to the submit button
  submitButton.addClass('shadow-submitted');
  // Send AJAX request
  $.ajax({
      url: '/',
      type: 'POST',
      data: formData,
      
      success: function(response) {
          $(formId+'_confirm_message').show(); 
          $(formId).find('.submit-error-message').hide();
          $(formId).hide();
      },
      error: function(xhr, status, error) {
          submitButton.removeClass('shadow-submitted');
          $(formId).css('opacity', '1');
          // Parse the response JSON
          var errorResponse = JSON.parse(xhr.responseText);
          // Extract the error message
          var errorMessage = errorResponse.errors.message[0];
          $(formId).find('.submit-error-message').text(errorMessage).show();
          grecaptcha.reset();
      }
  });
}

