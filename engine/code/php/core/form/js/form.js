var multipleCounters = 0;

var refreshForms = function () {

    $('*[data-multiple]').each(function () {
        var inputItem = $(this);
<<<<<<< HEAD
        var wrapper = $(this).closest('.form-item-multiple-wrapper');

        // In the case of multiple items...
        if (!($(this).closest('.form-item-wrapper').find('.form-item-actions').length)) {
            formItemMultipleActions(inputItem);
            //multipleCounters[];

            wrapper.find('.form-item-add').unbind().bind('click', function () {
                var rel_add = $(this).attr('href');
                var new_id = inputItem;
                var newFormItem = $(rel_add).clone().attr('id', new_id).attr('value', '');
                wrapper.after('<div class="form-item-multiple-wrapper">' + newFormItem.prop('outerHTML') + '</div>');
                formItemMultipleActions($('#' + new_id));
            });
=======
        refreshMultiple(inputItem);
    });
}
>>>>>>> f7d08fb5c8d655823a38d695f04dc6d03b8a0247

var refreshMultiple = function (inputItem) {
  var wrapper = inputItem.closest('.form-item-multiple-wrapper');
  var inputItemID = inputItem.attr('id');
  var inputItemName = inputItem.attr('name');
  var inputCounter = 0;
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
  // ADD BUTTON.
  var form_item_add_id = 'form-item-add-' + inputItemName;
  var last_item = $('*[name=' + inputItemName + ']').last();
  $('#' + form_item_add_id).detach();
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

$(document).bind('refresh', function () {
    refreshForms();
});
