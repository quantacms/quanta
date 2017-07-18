var multipleCounters = 0;

var refreshForms = function () {

    $('*[data-multiple]').each(function () {
        var inputItem = $(this);
        refreshMultiple(inputItem);
    });
}

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
      if (!$('#' + form_item_remove_id).length) {
        $(this).after('<input type="button" rel="' + $(this).attr('id') + '" id="' + form_item_remove_id + '" class="form-item-remove" value="-" />');
        $('#' + form_item_remove_id).unbind().bind('click', function () {
          $(this).closest('.form-item-multiple-wrapper').detach();
          refreshMultiple(inputItem);
        });
      }
  });

  // Remove - button if only one element present.
  if (inputCounter < 2) {
    wrapper.find('.form-item-remove').detach();
  }
  // Preparing the + button. Remove the existing one...
  var form_item_add_id = 'form-item-add-' + inputItemName;
  var last_item = $('*[name=' + inputItemName + ']').last();
  $('#' + form_item_add_id).detach();


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
    });
    }


}

$(document).bind('refresh', function () {
    refreshForms();
});

