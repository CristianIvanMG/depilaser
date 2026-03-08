//al presionar la tecla Enter en un campo con la clase focus cambie el cursor
//cambie al siguiente campo del formulario con la clase focus
$('.focus').keydown(function (e) {
    if (e.which === 13) {
        var index = $('.focus').index(this) + 1;
        $('.focus').eq(index).focus();
        return false;
    }
});