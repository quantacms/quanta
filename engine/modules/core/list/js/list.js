var refreshLists = function () {

    $('.list').each(function () {
        var rel = $(this).attr('rel');
        var tpl = $(this).data('tpl');
        var list = $(this);
        if (rel != undefined) {
            $(this).find('a.link').off('click').on('click', function (e) {
                list.find('.link-active').removeClass('link-active');

                if (rel == '_self') {
                    top.location.href = '/' + $(this).attr('href');
                }
                else if ($(this).attr('href') != '_empty') {
                    openAjax($(this).attr('href'), rel, undefined, tpl);
                    $(this).addClass('link-active');
                }
                e.preventDefault();
            });
        }
    });
};


$(document).bind('refresh', function () {
    refreshLists();
});
