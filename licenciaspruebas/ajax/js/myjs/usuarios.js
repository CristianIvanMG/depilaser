var accion = 'agregar';

$('#modal_form').on('shown.bs.modal', function () {
    $('#txt_codigo_1').focus();
});

$('#btn_agregar').click(function(){
    $('#modal_content').load('ajax/modal-content/usuarios_form.html');
    $('#lblModalHeader').text('Agregar registro');
    $('#modal_form').modal('show');
    accion = 'agregar';

});

                         
function accion_btn_guardar(){
    
    if(accion === 'agregar'){
        guarda_registro();
    }
    else{
        edita_registro();
    }
}

function guarda_registro(){
    try{
        
        var nombres = $('#txt_nombres').val().trim();
        var apellidos = $('#txt_apellidos').val();
        var email = $('#txt_email').val().trim();
        var usuario = $('#txt_usuario').val().trim(); 
        var password_1 = $('#txt_password_1').val().trim();
        var password_2 = $('#txt_password_2').val().trim();
        var activo = 1;
        if (!$('#chk_activo').is(':checked')) {
            activo = 0;
        }
        
      
        if(nombres === '' || apellidos === '' || email === '' || usuario === '' || password_1 === '' || password_2 === ''){
            mensaje_alerta('Capture los campos obligatorios');
        }
        if(nombres === ''){
            $('#txt_nombres').val('').focus();
            return;
        }
        if(apellidos === ''){
            $('#txt_apellidos').val('').focus();
            return;
        }
        if(email === ''){
            $('#txt_email').val('').focus();
            return;
        }
        
        if(usuario === ''){
            $('#txt_usuario').val('').focus();
            return;
        }
        if(password_1 === ''){
            $('#txt_password_1').val('').focus();
            return;
        }
        if(password_2 === ''){
            $('#txt_password_2').val('').focus();
            return;
        }
       
        //validamos que la dirección de correo tenga un formato válido
        var arroba_pos = email.indexOf("@");
        var punto_pos = email.lastIndexOf(".");
        if (arroba_pos < 1 || punto_pos<arroba_pos + 2 || punto_pos + 1 >= email.length) {
            mensaje_alerta('Capture una dirección de correo válida');
            $('#txt_email').focus();
            return;
        }
        
        if(password_1 !== password_2){
            mensaje_alerta('Las contraseñas no coinciden');
            $('#txt_password_2').val('').focus();
            return;
        }
        
        password_1 = sha256(password_1);
        
        var php_consulta = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + "usuarios_agregar.php";
        
          var datos = 'nombres=' + nombres +
            '&apellidos=' + apellidos +
            '&email=' + email +
            '&usuario=' + usuario +
            '&password=' + password_1 +
            '&activo=' + activo
        ;
        
        //alert(php_consulta);
        //alert(datos);
        //return;
        
    
    ////mostramos el loader
    //muestra_loader();
    $.ajax({
            type: 'POST',
            url: encodeURI(php_consulta),
            data: datos,
            crossDomain: true,
            cache: false,
            success: function (respuesta) {
                ////ocultamos el loader
                //oculta_loader();
                var datos_tabla = JSON.parse(respuesta);
                for (var i = 0; i < datos_tabla.length; i++) {
                    var status = datos_tabla[i].status;
                    var mensaje = datos_tabla[i].mensaje;
                }
                if(status === 'warning'){
                    mensaje_alerta(mensaje);
                    $('#txt_usuario').focus();
                }
                if(status === 'success'){
                    $('#modal_form').modal('toggle');
                    mensaje_exito(mensaje);
                    consulta_catalogo();
                }
                if(status === 'error'){
                    mensaje_error(mensaje);
                }
            },
            error: function (respuesta) {
                //oculta_loader();
                //alert(JSON.stringify(respuesta));
                var mensaje = 'No pude acceder al servidor en estos momentos, intente nuevamente y verifíque que el dispositivo tenga conexión a Internet y si el problema persiste comuníquese con el administrador.';
                mensaje_error(mensaje);
            }
        });
    }
    catch(err){
        //oculta_loader();
        alert(err.message);
    }
}

$('#btn_editar').click(function(){
    
    var n_seleccionados = tabla.rows('.selected').count();
    if(n_seleccionados === 0){
        mensaje_alerta('Selecciona un registro para continuar');
        return;
    }
    accion = 'editar';
    $('#modal_content').load('ajax/modal-content/usuarios_form.html');
    $('#lblModalHeader').text('Editar registro');
    $('#modal_form').modal('show');
    consulta_registro();
    
});

function consulta_registro() {
    try{
        var php_consulta = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + 'usuarios_consultar.php';
        var datos = 'id_seleccionado=' + id_seleccionado;
        ////mostramos el loader
        muestra_loader();
        $.ajax({
            type: 'POST',
            url: encodeURI(php_consulta),
            data: datos,
            crossDomain: true,
            cache: false,
            success: function (respuesta) {
                ////ocultamos el loader
                oculta_loader();
                //alert(respuesta);
                var datos_tabla = JSON.parse(respuesta);
                for (var i = 0; i < datos_tabla.length; i++) {
                    
                    var nombres = datos_tabla[i].nombres;
                    var apellidos = datos_tabla[i].apellidos;
                    var email = datos_tabla[i].email;
                    var nombre_usuario = datos_tabla[i].nombre_usuario;
                    var activo = datos_tabla[i].activo;
                    var administrador = datos_tabla[i].administrador;

                }
                
                $('#txt_nombres').val(nombres);
                $('#txt_apellidos').val(apellidos);
                $('#txt_email').val(email);
                $('#txt_usuario').val(nombre_usuario);
                if(activo == 0){
                     $('#chk_activo').prop('checked', false);
                }
                
            },
            error: function (respuesta) {
                oculta_loader();
                var mensaje = 'No pude acceder al servidor en estos momentos, intente nuevamente y verifíque que el dispositivo tenga conexión a internet y si el problema persiste comuniquese con el administrador.';
                mensaje_error(mensaje);
            }
        });
    }
    catch(err) {
        oculta_loader();
        alert(err.message); 
    }
}

//

function edita_registro(){
    try{
        
        var nombres = $('#txt_nombres').val().trim();
        var apellidos = $('#txt_apellidos').val();
        var email = $('#txt_email').val().trim();
        var usuario = $('#txt_usuario').val().trim(); 
        var password_1 = $('#txt_password_1').val().trim();
        var password_2 = $('#txt_password_2').val().trim();
        var activo = 1;
        if (!$('#chk_activo').is(':checked')) {
            activo = 0;
        }
        if(nombres === '' || apellidos === '' || email === '' || usuario === ''){
            mensaje_alerta('Capture los campos obligatorios');
        }
        if(nombres === ''){
            $('#txt_nombres').val('').focus();
            return;
        }
        if(apellidos === ''){
            $('#txt_apellidos').val('').focus();
            return;
        }
        if(email === ''){
            $('#txt_email').val('').focus();
            return;
        }
        
        if(usuario === ''){
            $('#txt_usuario').val('').focus();
            return;
        }
        
        //validamos que la dirección de correo tenga un formato válido
        var arroba_pos = email.indexOf("@");
        var punto_pos = email.lastIndexOf(".");
        if (arroba_pos < 1 || punto_pos<arroba_pos + 2 || punto_pos + 1 >= email.length) {
            mensaje_alerta('Capture una dirección de correo válida');
            $('#txt_email').focus();
            return;
        }
        
        if(password_1 !== '' || password_2 !== ''){
            if(password_1 !== password_2){
                mensaje_alerta('Las contraseñas no coinciden');
                $('#txt_password_2').val('').focus();
                return;
            }
            password_1 = sha256(password_1);
        }
        
        
        var php_consulta = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + "usuarios_editar.php";
        
        var datos = 'id_seleccionado=' + id_seleccionado + 
            '&nombres=' + nombres +
            '&apellidos=' + apellidos +
            '&email=' + email +
            '&usuario=' + usuario +
            '&password=' + password_1 +
            '&activo=' + activo
        ;
        
        //alert(datos);
        ////mostramos el loader
        //muestra_loader();
        $.ajax({
            type: 'POST',
            url: encodeURI(php_consulta),
            data: datos,
            crossDomain: true,
            cache: false,
            success: function (respuesta) {
                ////ocultamos el loader
                //oculta_loader();
                var datos_tabla = JSON.parse(respuesta);
                for (var i = 0; i < datos_tabla.length; i++) {
                    var status = datos_tabla[i].status;
                    var mensaje = datos_tabla[i].mensaje;
                    //var color = datos_tabla[i].color;
                    //var icono = datos_tabla[i].icono;
                    //var tiempo = datos_tabla[i].tiempo;
                }
                if(status === 'warning'){
                    mensaje_alerta(mensaje);
                    $('#txt_codigo_1').focus();
                }
                if(status === 'success'){
                    $('#modal_form').modal('hide');
                    mensaje_exito(mensaje);
                    consulta_catalogo();
                }
                if(status === 'error'){
                    mensaje_error(mensaje);
                }
            },
            error: function (respuesta) {
                //oculta_loader();
                var mensaje = 'No pude acceder al servidor en estos momentos, intente nuevamente y verifíque que el dispositivo tenga conexión a internet y si el problema persiste comuniquese con el administrador.';
                mensaje_error(mensaje);
            }
        });
    }
    catch(err){
        //oculta_loader();
        alert(err.message);
    }
}

//

function elimina_registro(){
    try{
        ////mostramos el loader
        muestra_loader();
        var php_consulta = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + "usuarios_eliminar.php";
        var datos = 'id_seleccionado=' + id_seleccionado;
        //alert(php_consulta);
        
        $.ajax({
            type: 'POST',
            url: encodeURI(php_consulta),
            data: datos,
            crossDomain: true,
            cache: false,
            success: function (respuesta) {
                 ////ocultamos el loader
                oculta_loader();
                var datos_tabla = JSON.parse(respuesta);
                for (var i = 0; i < datos_tabla.length; i++) {
                    var status = datos_tabla[i].status;
                    var mensaje = datos_tabla[i].mensaje;
                    //var color = datos_tabla[i].color;
                    //var icono = datos_tabla[i].icono;
                    //var tiempo = datos_tabla[i].tiempo;
                }
                if(status === 'warning'){
                    mensaje_alerta(mensaje);
                    //$('#txt_codigo_1').focus();
                }
                if(status === 'success'){
                    mensaje_exito(mensaje);
                    tabla.row('.selected').remove().draw(false);
                }
                if(status === 'error'){
                    mensaje_error(mensaje);
                }
                
            },
            error: function (respuesta) {
                oculta_loader();
                var mensaje = 'No pude acceder al servidor en estos momentos, intente nuevamente y verifíque que el dispositivo tenga conexión a internet y si el problema persiste comuniquese con el administrador.';
                mensaje_error(mensaje);
            }
        });
    }
    catch(err){
        oculta_loader();
        alert(err.message);
    }
}


$('#btn_eliminar').click(function(e){
    
    var n_seleccionados = tabla.rows('.selected').count();
    if(n_seleccionados === 0){
        mensaje_alerta('Selecciona un registro para continuar');
        return;
    }
    
    $.SmartMessageBox({
        title : 'Atención',
		content : '¿Realmente desea eliminar el registro seleccionado?\nEsta acción no podrá ser deshecha.',
		buttons : '[No][Si]'
		}, function(ButtonPressed) {
        if (ButtonPressed === 'Si') {
            elimina_registro();
        }
		if (ButtonPressed === 'No') {
            
        }
    });
	e.preventDefault();
    
});

//

function consulta_catalogo() {
    try{
        var php_consulta = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + 'usuarios_catalogo.php';
        var datos = '';
        
        ////mostramos el loader
        muestra_loader();
        $.ajax({
            type: 'POST',
            url: encodeURI(php_consulta),
            data: datos,
            crossDomain: true,
            cache: false,
            success: function (respuesta) {
                tabla.clear().draw();
                //alert(respuesta);
                var datos_tabla = JSON.parse(respuesta);
                for (var i = 0; i < datos_tabla.length; i++) {
                    
                    var id_usuario = datos_tabla[i].id_usuario;
                    var nombres = datos_tabla[i].nombres;
                    var apellidos = datos_tabla[i].apellidos;
                    var email = datos_tabla[i].email;
                    var nombre_usuario = datos_tabla[i].nombre_usuario;
                    var activo = datos_tabla[i].activo;
                    if(activo === 'NO'){
                        activo = '<span class="center-block padding-5 label label-danger">NO</span>';
                    }
                    else{
                        activo = '<span class="center-block padding-5 label label-success">SI</span>';
                    };
                    var administrador = datos_tabla[i].administrador;
                    
                    tabla.row.add(
                        [
                            '',
                            id_usuario, 
                            nombre_usuario, 
                            nombres + ' ' + apellidos, 
                            email, 
                            activo   
                        ]
                    ).draw();
                }
                
                //Insertamos la numeración de las filas de las tablas.
                tabla.on( 'order.dt search.dt', function () {
                    tabla.column(0, {search:'applied', order:'applied'}).nodes().each( function (cell, i) {
                        cell.innerText = i+1;
                    } );
                } ).draw();
    
                tabla.columns.adjust().draw(false);
                ////ocultamos el loader
                oculta_loader();
                calcula_totales();
            },
            error: function (respuesta) {
                oculta_loader();
                var mensaje = 'No pude acceder al servidor en estos momentos, intente nuevamente y verifíque que el dispositivo tenga conexión a internet y si el problema persiste comuniquese con el administrador.';
                alert(JSON.stringify(respuesta));
            }
        });
    }
    catch(err) {
        oculta_loader();
        alert(err.message); 
    }
}

function calcula_totales(){
    var n_filas = tabla.rows().count();
    $('#lbl_n_registros').html('usuarios <span class="txt-color-blue"><i class="fa fa-users"></i> ' + n_filas + '</span>');
}



function oculta_columnas_tabla(indices){
    $.each( indices, function( index, value ) {
        tabla.column(value).visible(false);  
    });
}
    
function muestra_columnas_tabla(indices){
    //lista de los indices de las columnas que queremos mostrar
    $.each( indices, function( index, value ) {
        tabla.column(value).visible(true);  
    });
}


$(document).ready(function () {
    //
    consulta_catalogo();
    //lista de los indices de las columnas de la tabla que queremos ocultar o mostrar
    var indices = [1];
    oculta_columnas_tabla(indices);
    
})();

