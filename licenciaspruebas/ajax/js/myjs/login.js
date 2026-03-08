//    
function limpia_campos(){
    $('#txt_password').val('');
    $('#txt_usuario').focus();
}
        
$("#txt_usuario").on('keyup', function (e) {
    if (e.keyCode === 13) {
      iniciar_sesion();
    }
});
    
$("#txt_password").on('keyup', function (e) {
    if (e.keyCode === 13) {
        iniciar_sesion();            
    }
});

function iniciar_sesion() {
    try {

        var usuario = $('#txt_usuario').val().trim();
        var password = $('#txt_password').val().trim();
        var status = '';
        var mensaje = '';
       
        if (usuario === '') {
            $('#mensaje').html('<span class="txt-color-red"><i class="fa fa-warning"></i> <strong>Capture su usuario por favor.</strong></span>');
            $('#txt_usuario').val('').focus();
            return;
        }
        if (password === '') {
            $('#mensaje').html('<span class="txt-color-red"><i class="fa fa-warning"></i> <strong>Capture su contraseña.</strong></span>');
            $('#txt_password').val('').focus();
            return;
        }
        password = sha256(password);
        
        var php_consulta = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + 'login.php';
        var datos = 'usuario=' + usuario +
            '&password=' + password;

        $.ajax({
            type: 'POST',
            url: encodeURI(php_consulta),
            data: datos,
            crossDomain: true,
            cache: false,
            success: function (respuesta) {
                //alert(respuesta);
                var datos_tabla = JSON.parse(respuesta);
                var x = datos_tabla.length;
                if (x === 0) {
                    $('#mensaje').html('<span class="txt-color-red"><i class="fa fa-warning"></i> <strong>Usuario o contraseña inválidos.</strong></span>');
                    $('#txt_password').focus();
                    return;
                }
                for (var i = 0; i < x; i++) {
                    var id_usuario = datos_tabla[i].id_usuario;
                    var nombres = datos_tabla[i].nombres;
                    var apellidos = datos_tabla[i].apellidos;
                    var activo = datos_tabla[i].activo;
                    var nombre_usuario = nombres + ' ' + apellidos;
                }
                if(activo == 0){
                    $('#mensaje').html('<span class="txt-color-red"><i class="fa fa-warning"></i> <strong>Usuario sin permisos de acceso.</strong></span>');
                    $('#txt_password').focus();
                    return;
                }
                guarda_datos_sesion(id_usuario, usuario, nombre_usuario, 1);
                $(location).attr('href', 'index.html');
                
            },
            error: function (respuesta) {
                oculta_loader();
                var mensaje = 'No pude acceder al servidor en estos momentos, intente nuevamente y verifíque que el dispositivo tenga conexión a internet y si el problema persiste comuniquese con el administrador.';
                //alert(mensaje);
            }
        });

    }
    catch (err) {
        alert(err.message);
    }

}
    


//botones
$('#btn_login').click(iniciar_sesion);


$(document).ready(function () {
    
    crea_bd();
    guarda_datos_sesion(0, '', '', 0);
    limpia_campos();
    
});
