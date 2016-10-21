function addPost(target) {
    if (target == undefined) {
        alert('no target for posting!');
        return;
    }
    openShadow({ module : 'post', context: 'post_add', type: 'tabs'});
}

$(document).ready(function() {
    $('.post-link').click(function () {
        addPost($(this).attr('rel'));
    });
});