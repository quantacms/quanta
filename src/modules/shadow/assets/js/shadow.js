var shadow;
var shadowUpdated = false;
var shadowConfirmClose = true;
/**
 * Close the shadow overlay when clicking outside the Shadow area.
 */
$(document).bind('refresh', function () {
  // When some update is done inside the shadow, make shadow aware.
  var setShadowUpdated = function () {
    shadowUpdated = true;
  };

  // If users have updated anything, make shadow aware, to prevent accidental
  // window closing, and losing of the work.
  $('#shadow-item').find('input,select,textarea').bind('change', setShadowUpdated);

  $('.shadow-submit').on('click', function () {
    if (!($(this).hasClass('shadow-submitted'))) {
      shadowConfirmClose = true;

      $(document).trigger('shadow_save');

      if (shadowConfirmClose) {
        $(this).addClass('shadow-submitted');
        // Trigger the shadow save hook.
        submitShadow();
      }
    }
  });

  $('.shadow-cancel').on('click', function () {
    closeShadow();
  });
});

// Close the shadow overlay.
function closeShadow() {
  // Prevent accidental closing of shadow when there are unsaved changes.
  if (!shadowUpdated || confirm('You have unsaved changes. Are you sure you want to close the window?')) {
    $('#shadow-outside, .shadow-element').hide();
  }
};

/**
 * Create the shadow wrapper HTML.
 *
 * @param stdClass shadowData
 */
function createShadow() {
  if (!($('#shadow-outside').length)) {
    $("body").append('<div id="shadow-outside" class="grid p-1 p-md-3"></div>');
    // Close the shadow overlay when clicking outside the Shadow area.
    $('#shadow-outside').on('click', function (event) {
      if (event.target !== this)
        return;
      closeShadow();
    });
  }
}

/**
 * Open a shadow (overlay form) with the specified parameters.
 *
 * @param stdClass shadowData
 */
function openShadow(shadowData) {
  if (!($('#shadow-outside').length)) {
    createShadow();
  }
  $(document).trigger('shadow', shadow);
  shadow = shadowData;
  shadowUpdated = false;
  if (shadow.widget == undefined) {
    shadow.widget = 'single';
  }

  // Define what to edit with shadow (URL).
  var shadowPath = '/' + ((shadow.node != undefined) ? (shadow.node) : '');
  shadowPath += '/?shadow=' + encodeURIComponent(JSON.stringify(shadow));

  console.log(shadowPath);
  // Add Language prefix for multilingual opening.
  if (shadow.language != undefined) {
    shadowPath = '/' + shadow.language + shadowPath;
  }

	// Retrieve the current URL
var currentUrl = window.location.href;

// Retrieve the protocol (http: or https:)
var protocol = window.location.protocol;

// Retrieve the host (domain.com, including port if present)
var host = window.location.host;

// Construct the base URL (protocol + "//" + host)
var baseUrl = protocol + "//" + host;
	shadowPath = baseUrl + shadowPath;
  $('#shadow-outside').html('').attr('data-rel', shadow.context).load(shadowPath, function () {
    if (shadow.callback != undefined) {
      shadow.callback();
    }

    if(shadowData?.language){
      // Change the language value
      $('#language').val(shadowData.language);
      }
    else{
      console.log('without lang');
      $('#language').val(null);
    }

    // Check if the element with ID "current_url" exists
    if ($('#current_url').length) {
      // If it exists, set its value
      var currentURL = window.location.href;
      //remove # from the url
      var cleanURL = currentURL.replace(/#.*$/, '');
      $('#current_url').val(cleanURL);
    }

    $(document).trigger('refresh');
    // Include attached scripts if present.
    if (shadow.attach != undefined) {
      for (i = 0; i < shadow.attach.length; i++) {
        $.getScript(shadow.attach[i]);
      }
    }

    $('a.shadow-title').on('click', function () {
      if (!($(this).hasClass('enabled'))) {
        $('.enabled').removeClass('enabled');
        $(this).addClass('enabled');
        $('#shadow-content-' + $(this).attr('data-rel')).addClass('enabled');
      }
      return false;
    });

    $(document).trigger('shadow_open');
    $(document).trigger('shadow_' + shadow.context);
  });
  $('#shadow-outside').fadeIn('medium');
};

/**
 * Submit a shadow form.
 * A shadow form could be made of several forms (one per each tab), for which we need
 * to "aggregate" input values, and submit them as if it was just one single, big form.
 */
function submitShadow() {
  $(document).trigger('shadow_' + shadow.context + '_submit');
  
  var form_items = {};
  var hasEmptyRequiredFields = false; // Flag to track if there are empty required fields
  
  $('#shadow-outside').find('input, textarea, select').each(function () {
    var inputField = $(this);
    var fieldWrapper = $(this).closest('.form-item-wrapper');
    
    var fieldName = inputField.attr('name');
    var fieldValue = inputField.val().trim();
            
    // Check if the field is required, empty, and visible
    if (inputField.prop('required') && fieldValue === '' && inputField.is(':visible')) {
      hasEmptyRequiredFields = true; // Set flag if a required field is empty
      
      // Add error message to the field wrapper
      fieldWrapper.addClass('has-validation-errors');
      if (fieldWrapper.find('.validation-error').length === 0) {
        fieldWrapper.prepend('<div class="validation-error">This field is required.</div>');
      }
    }
    else{
      // Remove error styling and message if field is not empty and visible
      fieldWrapper.removeClass('has-validation-errors');
      fieldWrapper.find('.validation-error').remove();
    }
    
    if (form_items[fieldName] == undefined) {
      form_items[fieldName] = getJSONFormItem(inputField,[]);
    }
    
    if (inputField.attr('type') == 'checkbox') {
      if(inputField.is(':checked')){
        var checkboxValue = inputField.is(':checked') ? inputField.val() : '';
        var newValue = !form_items[fieldName] ? [checkboxValue] :  (form_items[fieldName].value.push(checkboxValue), form_items[fieldName].value);
        form_items[fieldName] = getJSONFormItem(inputField,newValue);
      }
    } else {
      var newValue = !form_items[fieldName] ? [fieldValue] :  (form_items[fieldName].value.push(fieldValue), form_items[fieldName].value);
      form_items[fieldName] = getJSONFormItem(inputField,newValue);
    }

    // Check if it's a file input with the specified id
    if (inputField.attr('type') === 'file') {
      // Check if the input field has the multiple attribute
      var hasMultiple = inputField.prop('multiple');
      var setAsThumbnail = inputField.attr('thumbnail');
      if (!hasMultiple) {
        form_items['single_file']= getJSONFormItem(inputField,true);
      }
      if(String(setAsThumbnail).toLowerCase() === 'false'){
        form_items['set_as_thumbnail']= getJSONFormItem(inputField,false);
      }
    }

  });
  
  if (hasEmptyRequiredFields) {
    // Stop form submission if there are empty required fields
    $('.shadow-submit').removeClass('shadow-submitted'); // Remove shadow-submitted class
    return;
  }
  var formData = JSON.stringify(form_items);
  $(document).trigger('shadow_submit');
  action(formData);
}

function getJSONFormItem(inputField,value){
  return {
    "type" : inputField.prop('type'),
    "required": inputField.prop('required'),
    "length": inputField.data('length'),
    "value" : value
  };
}
