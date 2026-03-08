$('#btn_salir').click(function(e){
    
     $.SmartMessageBox({
        title : "<i class='fa fa-sign-out txt-color-orangeDark'></i> <span class='txt-color-orangeDark'><strong>" + nombre_usuario_logueado + "</strong></span>",
		content : "¿Desea cerrar su sesión?",
		buttons : '[No][Si]'
		}, function(ButtonPressed) {
        if (ButtonPressed === 'Si') {
            guarda_datos_sesion(0, '', '', 0);
            $.root_.addClass('animated fadeOutUp');
            setTimeout($(location).attr('href', 'login.html'), 1000);
        }
		
    });
	e.preventDefault();
});

$(document).ready(function () {
    
    consulta_datos_sesiones();
    if(id_usuario_logueado === 0){
        guarda_datos_sesion(0, '', '', 0);
        $(location).attr('href', 'login.html');        
    }
   $('#usuario_logueado').text(usuario_logueado);
    
});
