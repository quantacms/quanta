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
  // REMOVE button.
  $('*[name=' + inputItemName + ']').each(function() {
      var form_item_remove_id = 'form-item-remove-' + $(this).attr('id');
      if (!$('#' + form_item_remove_id).length) {
        $(this).after('<input type="button" rel="' + $(this).attr('id') + '" id="' + form_item_remove_id + '" class="form-item-remove" value="-" />');
        $('#' + form_item_remove_id).unbind().bind('click', function () {
          $(this).closest('.form-item-multiple-wrapper').detach();
          refreshMultiple($(this));
        });
      }
  });

  // ADD BUTTON.
  var form_item_add_id = 'form-item-add-' + inputItemName;
  $('#' + form_item_add_id).detach();
  $('*[name=' + inputItemName + ']').last().after('<input type="button" value="+" rel="' + inputItemID + '" id="' + form_item_add_id + '" class="form-item-add">');

  $('#' + form_item_add_id).unbind().bind('click', function () {
    multipleCounters++;
    var new_id = inputItemName + '_' + multipleCounters;
    var newFormItem = inputItem.clone().attr('id', new_id).attr('value', '');
    wrapper.after('<div class="form-item-multiple-wrapper">' + newFormItem.prop('outerHTML') + '</div>');
    refreshMultiple(inputItem);
  });



}

$(document).bind('refresh', function () {
    refreshForms();
});
