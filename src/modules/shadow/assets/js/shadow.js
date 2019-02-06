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
    shadow.widget = 'tabs';
  }

  var shadowPath = '/' + ((shadow.node != undefined) ? (shadow.node + '/') : '');
  shadowPath += '?shadow=' + JSON.stringify(shadow);

  if (shadow.language != undefined) {
    shadowPath += '&lang=' + shadow.language;
  }

  $('#shadow-outside').html('').attr('data-rel', shadow.context).load(shadowPath, function () {
    if (shadow.callback != undefined) {
      shadow.callback();
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
  $('#shadow-outside').find('input, textarea, select').each(function () {
    var item_name = $(this).attr('name');
    if (form_items[item_name] == undefined) {
      form_items[item_name] = [];
    }
    if ($(this).attr('type') == 'checkbox') {
      form_items[item_name].push($(this).is(':checked') ? $(this).val() : '');
    } else {
      form_items[item_name].push($(this).val());
    }
  });
  var formData = JSON.stringify(form_items);
  $(document).trigger('shadow_submit');
  action(formData);

};
