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
    $('*[name=' + inputItemName + ']').siblings('.form-item-remove').remove();
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
      removeDuplicateOptions(new_id);
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
  $('.ajax-form').on('submit', function(event) {
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

  InitializeTelInputs();
  InitializeAddressInputs();
  handleSelectChange();

 });

 function InitializeTelInputs(appendCss= true){
  if(appendCss){
    // Dynamically add the intl-tel-input CSS file
    $('<link>', {
      rel: 'stylesheet',
      type: 'text/css',
      href: 'https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.13/css/intlTelInput.css'
    }).appendTo('head');
  }

  // Initialize intl-tel-input for all input[type="tel"]
  $('input[type="tel"]').each(function() {
    const input = $(this);
    const iti = window.intlTelInput(this, {
      initialCountry: "it",
      utilsScript: "https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.13/js/utils.js"
    });

    // Store the iti instance in the input's data attribute
    input.data('iti', iti);
    // Create a hidden input to store the full phone number
    const hiddenInput = $('<input>', {
      type: 'hidden',
      name: 'full' + input.attr('name'),
      id: 'full' + input.attr('id'),
      required : input.attr('required')
    });
    input.after(hiddenInput);
  
    // Update the hidden input on input change and country change
    const updateHiddenInput = function() {
      hiddenInput.val(iti.getNumber());
    };
    input.on('input', updateHiddenInput);
    input.on('countrychange', updateHiddenInput);

    // Set the initial value of the hidden input
    updateHiddenInput();

    if(input.val() && !iti.getNumber()){
      // Set the value of the hidden input
      hiddenInput.val(input.val()); 
    }
  });
 }

 function InitializeAddressInputs() {
  $('.address-input').each(function() {
    const input = $(this);
    // Prevent the user to submit the form until enter the address
    if(input.val() === ''){
      $('#edit-save').addClass('shadow-submitted');
    }
    const loader = $('#loader'); // Reference to the loader element
    // Get the address inputs that we want to use them (these inputs must be added in the form that used)
    const roadInput = $('input[name="road"]');
    const streetNumberInput = $('input[name="street_number"]');
    const stateInput = $('input[name="state"]');
    const postcodeInput = $('input[name="postcode"]');
    const cityInput = $('input[name="city"]');
    const countryInput = $('input[name="country"]');
    const countryCodeInput = $('input[name="country_code"]');
    const latCodeInput = $('input[name="latitude"]');
    const lonCodeInput = $('input[name="longitude"]');

    input.autocomplete({
      source: function(request, response) {
        loader.show(); // Show the loader
        $.ajax({
          url: `https://geocode.search.hereapi.com/v1/geocode`,
          dataType: "json",
          data: {
            q: request.term,
            // Include your Here API key below
            apiKey: 'lDxfyBrF9You62PBblLf3K5WPoojbL9xlrPYw7GBAOQ',
            // Specify the type of result you want
            at: '52.5160,13.3779', // Replace with user's location or leave out for global results
            limit: 10 // Limit the number of suggestions
          },
          success: function(data) {
            response(data.items.map(item => ({
              label: item.address.label,
              lat: item.position.lat,
              lon: item.position.lng,
              road: item.address.street,
              road: item.address.street,
              state: item.address.state,
              city: item.address.city,
              postcode: item.address.postalCode,
              streetNumber: item.address.houseNumber,
              county: item.address.county,
              country: item.address.countryName,
              country_code: item.address.countryCode,
            })));
          },
          error: function(xhr, status, error) {
            console.error("Error fetching data from Here Places API:", status, error);
            response([]);
          },
          complete: function() {
            loader.hide(); // Hide the loader after the request is complete
          }
        });
      },
      select: function(event, ui) {
        // Allow the user to submit the form
        $('#edit-save').removeClass('shadow-submitted');
        // Check if fields are required and if any required field is missing
        const requiredFields = {
          city: cityInput,
          country: countryInput,
          country_code: countryCodeInput,
          lat: latCodeInput,
          lon: lonCodeInput
        };

        const isMissingData = Object.keys(requiredFields).some(field =>
          isRequiredFieldMissing(ui.item[field], requiredFields[field])
        );
        var fieldWrapper = $(this).closest('.form-item-wrapper');
        if (isMissingData) {
          // Prevent the user from submitting the form
          $('.shadow-submit').addClass('shadow-submitted');
          // Add error message to the field wrapper
          fieldWrapper.addClass('has-validation-errors');
          if (fieldWrapper.find('.validation-error').length === 0) {
            fieldWrapper.append(`<div class="validation-error">${$('#address-missing-data').text()}</div>`);
          }
        } else {
          $('.shadow-submitted').removeClass('shadow-submitted');
          // Remove error styling and message if field is not empty and visible
          fieldWrapper.removeClass('has-validation-errors');
          fieldWrapper.find('.validation-error').remove();
          // Update the hidden input
          if (ui.item.road) { roadInput.val(ui.item.road); }
          if (ui.item.streetNumber) { streetNumberInput.val(ui.item.streetNumber); }
          if (ui.item.state) { stateInput.val(ui.item.state); }
          if (ui.item.postcode) { postcodeInput.val(ui.item.postcode); }
          if (ui.item.city) { cityInput.val(ui.item.city); }
          if (ui.item.country) { countryInput.val(ui.item.country); }
          if (ui.item.country_code) { countryCodeInput.val(ui.item.country_code); }
          if (ui.item.lat) { latCodeInput.val(ui.item.lat); }
          if (ui.item.lon) { lonCodeInput.val(ui.item.lon); }
        }
      },
      minLength: 3, // Minimum length of characters before triggering suggestions
      appendTo: "#wrapper-address",
      position: {
        my: "left top",
        at: "left bottom",
        collision: "none" // Prevents the dropdown from getting cut off
      }
    });
  });
}

//openstreetmap approach (deprecated)
// function InitializeAddressInputs(){
//   $('.address-input').each(function() {
//     const input = $(this);
//     const loader = $('#loader'); // Reference to the loader element
//     // get the address inputs that we want to use them (these inputs must be added in the form that used) you can add any input of them According to your needs
//     const roadInput = $('input[name="road"]');
//     const stateInput = $('input[name="state"]');
//     const postcodeInput = $('input[name="postcode"]');
//     const cityInput = $('input[name="city"]');
//     const countryInput = $('input[name="country"]');
//     const countryCodeInput = $('input[name="country_code"]');
//     const latCodeInput = $('input[name="latitude"]');
//     const lonCodeInput = $('input[name="longitude"]');

//     input.autocomplete({
//       source: function(request, response) {
//         loader.show(); // Show the loader
//         $.ajax({
//           url: `https://nominatim.openstreetmap.org/search`,
//           dataType: "json",
//           data: {
//             q: request.term,
//             format: "json",
//             addressdetails: 1,
//             limit: 10 // Limit the number of suggestions
//           },
//           success: function(data) {
//             response(data.map(item => ({
//               label: item.display_name,
//               lat: item.lat,
//               lon: item.lon,
//               road: item.address.road,
//               state: item.address.state,
//               city: item.address.town || item.address.village,
//               postcode: item.address.postcode,
//               country: item.address.country,
//               country_code: item.address.country_code,
//             })));
//           },
//           error: function(xhr, status, error) {
//             console.error("Error fetching data from Nominatim:", status, error);
//             response([]);
//           },
//           complete: function () {
//             loader.hide(); // Hide the loader after the request is complete
//           }
//         });
//       },
//       select: function(event, ui) {
//         console.log("Selected address:", ui.item);
//           // Check if fields are required and if any required field is missing
//         const requiredFields = {
//           road: roadInput,
//           state: stateInput,
//           postcode: postcodeInput,
//           city: cityInput,
//           country: countryInput,
//           country_code: countryCodeInput,
//           lat: latCodeInput,
//           lon: lonCodeInput
//         };

//         const isMissingData = Object.keys(requiredFields).some(field =>
//           isRequiredFieldMissing(ui.item[field], requiredFields[field])
//         );
//         var fieldWrapper = $(this).closest('.form-item-wrapper');
//         if(isMissingData){
//           //prevent the user to submit the form
//           $('.shadow-submit').addClass('shadow-submitted');
//           // Add error message to the field wrapper
//           fieldWrapper.addClass('has-validation-errors');
//           if (fieldWrapper.find('.validation-error').length === 0) {
//             fieldWrapper.append(`<div class="validation-error">${$('#address-missing-data').text()}</div>`);
//           }
//         }
//         else{
//           $('.shadow-submitted').removeClass('shadow-submitted');
//           // Remove error styling and message if field is not empty and visible
//           fieldWrapper.removeClass('has-validation-errors');
//           fieldWrapper.find('.validation-error').remove();
//           // Update the hidden input
//           if(ui.item.road){ roadInput.val(ui.item.road);}
//           if(ui.item.state){ stateInput.val(ui.item.state);}
//           if(ui.item.postcode){ postcodeInput.val(ui.item.postcode);}
//           if(ui.item.city){ cityInput.val(ui.item.city);}
//           if(ui.item.country){ countryInput.val(ui.item.country);}
//           if(ui.item.country_code){ countryCodeInput.val(ui.item.country_code);}
//           if(ui.item.lat){ latCodeInput.val(ui.item.lat);}
//           if(ui.item.lon){ lonCodeInput.val(ui.item.lon);}
//         }
       
//       },
//       minLength: 3 // Minimum length of characters before triggering suggestions
//     });
//   });

//  }

 // Function to check if a field is required and if its value is present
function isRequiredFieldMissing(value, input) {
  return input.is('[required]') && (!value || (typeof value === 'string' && value.trim() === '' ));
}

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

  // Dispatch a custom event before ajax request called
  var event = new CustomEvent('formSubmission', {
    detail: {
        formId: formId
    }
  });
  document.dispatchEvent(event);

  // Send AJAX request
  $.ajax({
      url: '/',
      type: 'POST',
      data: formData,
      
      success: function(response) {
          $(formId+'_confirm_message').show(); 
          $(formId).find('.submit-error-message').hide();
          $(formId).hide();
           // Dispatch a custom event on success
           var event = new CustomEvent('formSubmissionSuccess', {
            detail: {
                formId: formId,
                response: response
            }
          });
          document.dispatchEvent(event);
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

function removeDuplicateOptions(id) {
  // Get the target <select> element based on the provided ID
  var $targetSelect = $('#' + id);

  if ($targetSelect.length === 0) return;

  // Gather selected values from all other <select> elements
  var selectedValues = $('.form-item-select').not($targetSelect).map(function() {
    return $(this).val();
  }).get().filter(Boolean);

  if (!selectedValues.length) return;

  // Hide matching options in the target <select> element
  $targetSelect.find('option').each(function() {
    var $option = $(this);
    var optionValue = $option.val();

    if (selectedValues.includes(optionValue)) {
      $option.hide().prop('disabled', true).removeAttr('selected');
      
      // If the current option was selected, select the next available option
      if ($option.is(':selected')) {
        var $nextOption = $option.next('option:enabled');
        $nextOption.prop('selected', true);
        $targetSelect.val($nextOption.val());
      }
    } else {
      $option.show().prop('disabled', false);
    }
  });

  handleSelectChange();
}

function handleSelectChange() {
  $('.form-item-select[data-multiple="true"]').off('change').on('change', function() {
    var selectedValues = $('.form-item-select').map(function() {
      return $(this).val();
    }).get().filter(Boolean);

    if (!selectedValues.length) return;

    // Update all selects to remove selected values
    $('.form-item-select').each(function() {
      var $select = $(this);
      if($(this).data('multiple') != true){  return ;}
      $select.find('option').each(function() {
        var $option = $(this);
        var optionValue = $option.val();

        if (selectedValues.includes(optionValue) && optionValue !== $select.val()) {
          $option.hide().prop('disabled', true).removeAttr('selected');
        } else {
          $option.show().prop('disabled', false);
        }
      });
    });
  });
}


