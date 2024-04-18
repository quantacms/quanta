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
};

/**
 * Refresh autocomplete fields.
 */
var refreshAutocomplete = function() {

  $("input.autocomplete").each(function() {
    var node = $(this).data('node');
    var options = {
      url: function(phrase) {
        var autocomplete_path = "/autocomplete?search_string=" + phrase + "&search_node=" + node;
        return autocomplete_path;
      },
      getValue: "name",
      placeholder: "write your tag here",
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
        maxNumberOfElements: 5,
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

