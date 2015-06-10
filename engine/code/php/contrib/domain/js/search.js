/**
 * Created by aldotripiciano on 20/05/15.
 */
$(document).ready(function() {
    var search_id = 0;
    $('#search').click(function() {
        search_id++;
        $.ajax({
            dataType: 'json',
            url: '/searchbox/?i=' + search_id + '&domain=' + $('#domain').val() + '&extension=' + $('#extension').val(),
            success: (function(data) {

                var searchbox = '<div class="searchbox" id="search-' + search_id + '"><div class="searching"><div class="domainstring">' + $('#domain').val() + '</div></div><div class="progressbar"><div class="percent">0%</div><div class="progress"></div></div><div class="progress-numbers"><span class="domains-checked">0</span> / <span class="domains-total"></span> done.</div><span style="display: none" class="starting-time"></span><div class="elapsed-time">Elapsed time: <span class="elapsed-time-span"></span></div><div class="average-time">Average time: <span class="average-time-span"></span></div><div class="actions"><a href="#" class="action" id="close">X</a><a href="#" class="action" id="pause">◼</a><a href="#" class="action" id="play">▶</a></div></div>';
                $('#searches').prepend(searchbox);
                generateDomains($('#domain').val(), $('#extension').val(), search_id);
                $('#domain').val('');
            }),
            error: function(error) { domainError(error); }
        });

        return false;
    });

    refreshSearchButtons();
});

var domainError = function(data, error) {
    setDomainMessage('ERR;Error: ' + data.responseText);
}
var setDomainMessage = function(data) {
    msgarr = data.split(';');
    $('#domain-messages').attr('class', 'message message-' + msgarr[0].toLowerCase()).html(msgarr[1]).show();
    setTimeout(function() {
        $('#domain-messages').fadeOut('slow');
    }, 8000);
}

var openWindow = function(page) {
    $('#results').hide();
    $('#window-inner').load(page, function() {
        $('#window').fadeIn('medium');
        refreshSearchButtons();
    });
}

var refreshSearchButtons = function() {

    $('#window-close').click(function() {
       $('#window').hide();
       $('#results').show();
    });


    $('.check-favorites').unbind().bind('click', function() {
        openShadow({ module : 'user', context: 'user_edit', type: 'tabs'});
    });

    $('.favorite').unbind('click').bind('click', function() {
        if ($(this).parent('li').hasClass('.favorite-item')) {
            $(this).parent('li').fadeOut('fast');
        }
        var fav_status = $(this).hasClass('selected') ? 0 : 1;
        $(this).toggleClass('selected');
        var fav_star = $(this);
        var fav_domain = $(this).attr('rel');
        $.ajax({
            dataType: 'json',
            url: '/domainAction/?action=favorite&domain=' + fav_domain + '&key=' + $('#key').val()+'&value=' + fav_status,
            success: (function(data) {
               fav_star.replaceWith(getStar(fav_domain, fav_status));
               refreshSearchButtons();
            })
        });
        return false;
    });
}

var getStar = function(domain, fav_status) {
    if (fav_status == 1) {
      var star = '<a href="#" rel="'  + domain +'" class="favorite selected">&#9733;</a>';
    } else {
        var star = '<a href="#" rel="'  + domain +'" class="favorite">&#9734;</a>';
    }
    return star;
}

function generateDomains(domains, ext, search_id) {
    $('#starting-time').html(Date.now());
    $.ajax({
        dataType: 'json',
        url: "/domainAction/?action=generate&regex=" + domains + '&key=' + $('#key').val(),
        success: (function(data) {
            var domain_items = [];
            $('#search-start').show();
            $.each(data.domains, function(i, domain) {
                domain_items[i] = domain + '.' + ext;
            });
            $('#search-'+ search_id).find('.domains-total').text(domain_items.length);
            checkStatistics(search_id);
            $('#OK-wrapper').fadeIn('slow');
            $('#KO-wrapper').fadeIn('slow');
            checkDomain(domain_items, 0, search_id);
        }),
        error: function(error) { domainError(error); }
    });
}

function checkStatistics(search_id) {
    var elapsed = parseInt((Date.now() - $('#search-' + search_id).find('.starting-time').text()) / 1000);
    var average = elapsed / $('.domains-checked').text();
    $('#search-' + search_id).find('.elapsed-time-span').html(elapsed + "s");
    $('#search-' + search_id).find('.average-time-span').html(Math.round(average).toFixed(2) + "s / domains");

    if ($('.domains-total').text() == $('.domains-checked').text()) {
        setDomainMessage('OK;Search n. ' + search_id + ' done!');
    } else {
        setTimeout('checkStatistics()', 500);
    }
}

function checkDomain(domains,  i, search_id) {
    $('#search-'+search_id).find('.domains-checked').text(i);
    var percent = parseInt(i / (domains.length) * 100);
    $('#search-'+search_id).find('.percent').html(percent + '%');
    $('#search-'+search_id).find('.progress').css('width', percent+'%');
    if (i>=domains.length) {
        return 0;
    }

    $.ajax({
        dataType: "json",
        url: "/domainAction/?action=search&domain=" + domains[i]+"&key=" + $('#key').val(),
        error: (function(error) {
            setDomainMessage('ERR;We have an error in getting data from the server: <b>' + error.responseText +'</b>. <br />Trying again in some seconds...');
            setTimeout(function() {
                checkDomain(domains, i, search_id);
            }, 3000);
        }),
        success: (function(data) {
            $('#key-used').text(data.used);
            $('#key-limit').text(data.limit);
            var actions = '';

            actions += getStar(domains[i], data.favorite == 1);

            if (data.status == 'OK') {
                $('#OK-body').append('<tr><td>' + i + '</td><td class="OK-domain">' + domains[i] + '</td><td class="backlinks">' + data.startpage + '</td><td class="nettaken-' + data.otherext.net + '">' + data.otherext.net + '</td><td>' + actions + '</td></tr>');

            } else {
                $('#KO').append('<tr><td>' + i + '</td><td class="KO-domain">' + domains[i] + '</td><td>' + data.expire_date+ '</td><td class="email">' + data.email + '</td><td>'+ actions +'</td></tr>');
            }

            $(".tablesorter").trigger("update").trigger('appendCache');
            refreshSearchButtons();
            checkDomain(domains, i+1, search_id);
        })
    })

}
