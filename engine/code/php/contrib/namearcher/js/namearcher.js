var refreshDomainBuilder = function() {
    $('.domain-collection').find('a').click(function() {
        var domain_id = $(this).attr('rel');
        $('#domain-search').append('<a rel="' + domain_id + '">'+ domain_id + '</div>');
        closeShadow();

    });
}


/**
 * Created by aldotripiciano on 20/05/15.
 */
$(document).ready(function () {
    $('#domain-piece-add').click(function() {
        openShadow({ module : 'namearcher', context: 'domain_collections', type: 'single', callback: refreshDomainBuilder});
    });

    $.ajax({
        url: 'http://www.esdeath.com',
        dataType: 'jsonp',
        type: 'GET',
        success: function (data) {
            console.log(data);
        },
        error: function (data) {
            console.log(data);
        }

    });

});

