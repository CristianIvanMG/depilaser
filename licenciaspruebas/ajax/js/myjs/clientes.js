var accion = 'agregar';

$('#modal_form').on('shown.bs.modal', function () {
    $('#txt_codigo_1').focus();
});

$('#btn_agregar').click(function(){
    $('#modal_content').load('ajax/modal-content/clientes_form.html');
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
        var codigo_cliente = $('#txt_codigo').val().trim();
        var empresa = $('#txt_empresa').val().trim();
        var nombres = $('#txt_nombres').val().trim();
        var apellidos = $('#txt_apellidos').val();
        var telefono = $('#txt_telefono').val();
        var email = $('#txt_email').val().trim();
        var calle = $('#txt_calle').val().trim();
        var colonia = $('#txt_colonia').val().trim();
        var cp = $('#txt_cp').val().trim();
        var pais = $('#txt_pais').val().trim();
        
        if(codigo_cliente === '' || empresa === '' || email === ''){
            mensaje_alerta('Capture los campos obligatorios');
        }
        if(codigo_cliente === ''){
            $('#txt_codigo').val('').focus();
            return;
        }
        if(empresa === ''){
            $('#txt_empresa').val('').focus();
            return;
        }
        if(email === ''){
            $('#txt_email').val('').focus();
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
    
        var php_consulta = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + "clientes_agregar.php";
        
        var datos = 'codigo_cliente=' + codigo_cliente +
            '&empresa=' + empresa +
            '&nombres=' + nombres +
            '&apellidos=' + apellidos +
            '&telefono=' + telefono +
            '&email='+ email + 
            '&calle='+ calle + 
            '&colonia='+ colonia + 
            '&cp='+ cp + 
            '&pais='+ pais 
            
        ;
    //alert(php_consulta);
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
                    $('#txt_codigo_1').focus();
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
    $('#modal_content').load('ajax/modal-content/clientes_form.html');
    $('#lblModalHeader').text('Editar registro');
    $('#modal_form').modal('show');
    consulta_registro();
    
});

function consulta_registro() {
    try{
        var php_consulta = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + 'clientes_consultar.php';
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
                    
                    var codigo_cliente = datos_tabla[i].codigo_cliente;
                    var empresa = datos_tabla[i].empresa;
                    var nombres = datos_tabla[i].nombres;
                    var apellidos = datos_tabla[i].apellidos;
                    var email = datos_tabla[i].email;
                    var telefono_1 = datos_tabla[i].telefono_1;
                    var direccion = datos_tabla[i].direccion;
                    var colonia = datos_tabla[i].colonia;
                    var cp = datos_tabla[i].cp;
                    var pais = datos_tabla[i].pais;

                }
                //$('#txt_codigo').prop('disabled', 'true');
                
                $('#txt_codigo').val(codigo_cliente);
                $('#txt_empresa').val(empresa);
                $('#txt_nombres').val(nombres);
                $('#txt_apellidos').val(apellidos);
                $('#txt_telefono').val(telefono_1);
                $('#txt_email').val(email);
                $('#txt_calle').val(direccion);
                $('#txt_colonia').val(colonia);
                $('#txt_cp').val(cp);
                $('#txt_pais').val(pais);
                
                
                
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
        
        var codigo_cliente = $('#txt_codigo').val().trim();
        var empresa = $('#txt_empresa').val().trim();
        var nombres = $('#txt_nombres').val().trim();
        var apellidos = $('#txt_apellidos').val();
        var telefono = $('#txt_telefono').val();
        var email = $('#txt_email').val().trim();
        var calle = $('#txt_calle').val().trim();
        var colonia = $('#txt_colonia').val().trim();
        var cp = $('#txt_cp').val().trim();
        var pais = $('#txt_pais').val().trim();
        
        if(codigo_cliente === '' || empresa === '' || email === ''){
            mensaje_alerta('Capture los campos obligatorios');
        }
        if(codigo_cliente === ''){
            $('#txt_codigo').val('').focus();
            return;
        }
        if(empresa === ''){
            $('#txt_empresa').val('').focus();
            return;
        }
        if(email === ''){
            $('#txt_email').val('').focus();
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
    
        var php_consulta = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + "clientes_editar.php";
        
        var datos = 'id_seleccionado=' + id_seleccionado +
            '&codigo_cliente=' + codigo_cliente +
            '&empresa=' + empresa +
            '&nombres=' + nombres +
            '&apellidos=' + apellidos +
            '&telefono=' + telefono +
            '&email='+ email + 
            '&calle='+ calle + 
            '&colonia='+ colonia + 
            '&cp='+ cp + 
            '&pais='+ pais 
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
        var php_consulta = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + "clientes_eliminar.php";
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
        var php_consulta = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + 'clientes_catalogo.php';
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
                    
                    var id_cliente = datos_tabla[i].id_cliente;
                    var codigo_cliente = datos_tabla[i].codigo_cliente;
                    var empresa = datos_tabla[i].empresa;
                    var nombres = datos_tabla[i].nombres;
                    var apellidos = datos_tabla[i].apellidos;
                    var email = datos_tabla[i].email;
                    var telefono_1 = datos_tabla[i].telefono_1;
                    var direccion = datos_tabla[i].direccion;
                    var colonia = datos_tabla[i].colonia;
                    var cp = datos_tabla[i].cp;
                    var pais = datos_tabla[i].pais;
                    
                    tabla.row.add(
                        [
                            '',
                            id_cliente, 
                            codigo_cliente, 
                            nombres + ' ' + apellidos, 
                            empresa, 
                            telefono_1, 
                            email, 
                            direccion + ' ' + colonia + ' ' + cp + ' ' + pais
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
    $('#lbl_n_registros').html('Clientes <span class="txt-color-blue"><i class="fa fa-users"></i> ' + n_filas + '</span>');
}

//lista de los indices de las columnas de la tabla que queremos ocultar o mostrar
var indices = [1];

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
    oculta_columnas_tabla(indices);
    
})();

