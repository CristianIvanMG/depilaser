$('#modal_form').on('shown.bs.modal', function () {
    $('#txt_codigo_1').focus();
});

$('#btn_agregar').click(function(){
    
    $('#modal_content').load('ajax/modal-content/licencias_form.html');
    $('#lblModalHeader').text('Agregar registro');
    $('#modal_form').modal('show');

});

$('#btn_editar').click(function(){
    
    var n_seleccionados = tabla.rows('.selected').count();
    if(n_seleccionados === 0){
        mensaje_alerta('Selecciona un registro para continuar');
        return;
    }
    $('#modal_lic_content').load('ajax/modal-content/licencias_activas_form.html');
    //$('#lblModalHeader').text('Licencias activadas');
    consulta_registro();
    $('#modal_licencias').modal('show');
 
});

function consulta_registro() {
    try{
        var php_consulta = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + 'licencias_activas_consultar.php';
        //var cliente_seleccionado = tabla.cell(index_seleccionado, 2).data();
        var codigo_activacion = tabla.cell(index_seleccionado, 7).data(); 
        //alert(cliente_seleccionado + ' - ' + producto_seleccionado);
        var datos = 'codigo_activacion=' + codigo_activacion;
        //alert(datos);
        ////mostramos el loader
        muestra_loader();
        $.ajax({
            type: 'POST',
            url: encodeURI(php_consulta),
            data: datos,
            crossDomain: true,
            cache: false,
            success: function (respuesta) {
                //alert(respuesta);
                
                var datos_tabla = JSON.parse(respuesta);
                for (var i = 0; i < datos_tabla.length; i++) {
                    
                    var id_dispositivo_activado = datos_tabla[i].id_dispositivo_activado;
                    var cliente = datos_tabla[i].cliente;
                    var id_activacion = id_dispositivo_activado + cliente;
                    var nombre_dispositivo = datos_tabla[i].nombre_dispositivo;
                    var tipo_dispositivo = datos_tabla[i].tipo_dispositivo;
                    var plataforma_dispositivo = datos_tabla[i].plataforma_dispositivo;
                    var modelo_dispositivo = datos_tabla[i].modelo_dispositivo;
                    var version_dispositivo = datos_tabla[i].version_dispositivo;
                    var fabricante_dispositivo = datos_tabla[i].fabricante_dispositivo;
                    var id_dispositivo = datos_tabla[i].id_dispositivo;
                    var llave_activacion = datos_tabla[i].llave_activacion;
                    var fecha_activacion = datos_tabla[i].fecha_activacion;
                    var fecha_fin_vigencia = datos_tabla[i].fecha_fin_vigencia;
                    var n_activaciones = datos_tabla[i].n_activaciones;
                    var eliminado = datos_tabla[i].eliminado;
                    if(eliminado === 'NO'){
                        eliminado = '<span class="center-block padding-5 label label-success">NO</span>';
                    }
                    else{
                        eliminado = '<span class="center-block padding-5 label label-danger">SI</span>';
                    };
                    
                    var lista_negra = datos_tabla[i].lista_negra;
                    if(lista_negra === 'NO'){
                        lista_negra = '<span class="center-block padding-5 label label-success">NO</span>';
                    }
                    else{
                        lista_negra = '<span class="center-block padding-5 label label-danger">SI</span>';
                    };
                    
                 tabla_lic.row.add(
                        [
                            '',
                            id_dispositivo_activado, 
                            id_activacion, 
                            nombre_dispositivo, 
                            tipo_dispositivo, 
                            llave_activacion, 
                            fecha_activacion, 
                            fecha_fin_vigencia, 
                            n_activaciones,
                            eliminado, 
                            lista_negra 
                        ]
                    ).draw();
                }
                
                //Insertamos la numeración de las filas de las tablas.
                tabla_lic.on( 'order.dt search.dt', function () {
                    tabla_lic.column(0, {search:'applied', order:'applied'}).nodes().each( function (cell, i) {
                        cell.innerText = i+1;
                    } );
                } ).draw();
                
                //tabla_lic.column( 2 ).visible(false); //temporal
                
                tabla_lic.columns.adjust().draw(false);
                ////ocultamos el loader
                oculta_loader();
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
    
        var php_consulta = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + "licencias_editar.php";
        
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
        muestra_loader();
        $.ajax({
            type: 'POST',
            url: encodeURI(php_consulta),
            data: datos,
            crossDomain: true,
            cache: false,
            success: function (respuesta) {
                oculta_loader();
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
                    mensaje_exito(mensaje);
                    $('#modal_form').modal('toggle');
                    consulta_catalogo();
                }
                if(status === 'error'){
                    mensaje_error(mensaje);
                }
                        
                ////ocultamos el loader
               
                
            },
            error: function (respuesta) {
                oculta_loader();
                var mensaje = 'No pude acceder al servidor en estos momentos, intente nuevamente y verifíque que el dispositivo tenga conexión a internet y si el problema persiste comuniquese con el administrador.';
                alert(respuesta);
            }
        });
    }
    catch(err){
        alert(err.message);
    }
}

//

function elimina_registro(){
    try{
        
        var php_consulta = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + "licencias_eliminar.php";
        var datos = 'id_seleccionado=' + id_seleccionado;
        //alert(php_consulta);
        ////mostramos el loader
        
        muestra_loader();
        
        $.ajax({
            type: 'POST',
            url: encodeURI(php_consulta),
            data: datos,
            crossDomain: true,
            cache: false,
            success: function (respuesta) {
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
                        
                ////ocultamos el loader
                oculta_loader();
                
            },
            error: function (respuesta) {
                oculta_loader();
                var mensaje = 'No pude acceder al servidor en estos momentos, intente nuevamente y verifíque que el dispositivo tenga conexión a internet y si el problema persiste comuniquese con el administrador.';
                mensaje_error(mensaje);
            }
        });
    }
    catch(err){
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
        var php_consulta = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + 'licencias_catalogo.php';
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
                    
                    var id = datos_tabla[i].id;
                    var cliente = datos_tabla[i].cliente;
                    var empresa = datos_tabla[i].empresa;
                    var contacto = datos_tabla[i].contacto;
                    var codigo_producto = datos_tabla[i].codigo_producto;
                    var codigo_activacion = datos_tabla[i].codigo_activacion;
                    var nombre_producto = datos_tabla[i].nombre_producto;
                    var n_licencias = datos_tabla[i].n_licencias;
                    var tipo_licencia = datos_tabla[i].tipo_licencia;
                    var fecha = datos_tabla[i].fecha;
                    
                    tabla.row.add(
                        [
                            '',
                            cliente, 
                            empresa, 
                            contacto, 
                            codigo_producto, 
                            //'<span class="center-block padding-5 label bg-color-greenDark font-sm">' + codigo_activacion + '</span>', 
                            nombre_producto, 
                            tipo_licencia, 
                            codigo_activacion, 
                            '<strong>' + n_licencias + '</strong>',
                            fecha 
                        ]
                    ).draw();
                }
                
                //Insertamos la numeración de las filas de las tablas.
                tabla.on( 'order.dt search.dt', function () {
                    tabla.column(0, {search:'applied', order:'applied'}).nodes().each( function (cell, i) {
                        cell.innerText = i+1;
                    } );
                } ).draw();
                
                //tabla.column( 2 ).visible(false); //temporal
                
                tabla.columns.adjust().draw(false);
                ////ocultamos el loader
                oculta_loader();
                calcula_totales();
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

function calcula_totales(){
    var n_filas = tabla.rows().count();
    var n_productos = tabla.column(8).data().sum();
    //$('#lbl_n_registros').html('Licencias vendidas <span class="txt-color-blue"><i class="fa fa-key"></i> ' + n_filas + '</span>');
    $('#lbl_n_registros').html('Licencias vendidas <span class="txt-color-blue"><i class="fa fa-key"></i> ' + n_productos + '</span>');
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
    
    consulta_catalogo();
    
    //lista de los indices de las columnas de la tabla que queremos ocultar o mostrar
    var indices = [1];
    oculta_columnas_tabla(indices);
    
})();

