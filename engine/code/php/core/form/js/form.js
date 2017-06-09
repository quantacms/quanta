var multipleCounters = [];

var refreshForms = function () {

    $('input[data-multiple]').each(function () {

        var inputItem = $(this);
        var wrapper = $(this).closest('.form-item-multiple-wrapper');

        // In the case of multiple items...
        if (!($(this).closest('.form-item-wrapper').find('.form-item-actions').length)) {
            formItemMultipleActions(inputItem);
            //multipleCounters[];

            wrapper.find('.form-item-add').unbind().bind('click', function () {
                var rel_add = $(this).attr('href');
                var new_id = inputItem;
                var newFormItem = $(rel_add).clone().attr('id', new_id).attr('value', '');
                wrapper.before('<div class="form-item-multiple-wrapper">' + newFormItem.prop('outerHTML') + '</div>');
                formItemMultipleActions($('#' + new_id));
            });

            wrapper.find('.form-item-remove').on('click', function () {
                $(this).closest('.form-item-multiple-wrapper').remove();
            });
            refreshForms();
        }


    });
}

var formItemMultipleActions = function (inputItem) {
    var input_id = inputItem.attr('id');
    inputItem.after('<div class="form-item-actions"><a href="#' + input_id + '" class="form-item-add">+</a><a href="#' + input_id + '" class="form-item-remove">-</a></div>');
}

$(document).bind('refresh', function () {
    refreshForms();
});
