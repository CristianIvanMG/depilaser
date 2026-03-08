var accion = 'agregar';

$('#modal_registro_nuevo').on('shown.bs.modal', function () {
    $('#txt_codigo_1').focus();
});

$('#btn_agregar').click(function(){
    
    $('#modal_content').load('ajax/modal-content/productos_form.html');
    $('#lblModalHeader').text('Agregar registro');
    $('#modal_registro_nuevo').modal('show');
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
    
    var codigo_1 = $('#txt_codigo_1').val().trim();
    var nombre_producto = $('#txt_nombre_producto').val().trim();
    var descripcion = $('#txt_descripcion').val().trim();
    var plataforma = $('#combo_plataforma').val();
    var precio_venta = $('#txt_precio').val();
    var link_descarga = $('#txt_link_descarga').val().trim();
    
    if(codigo_1 === '' || nombre_producto === '' || descripcion === '' || plataforma === null){
        mensaje_alerta('Capture los campos obligatorios');
    }
    if(codigo_1 === ''){
        $('#txt_codigo_1').val('').focus();
        return;
    }
    if(nombre_producto === ''){
        $('#txt_nombre_producto').val('').focus();
        return;
    }
    if(descripcion === ''){
        $('#txt_descripcion').val('').focus();
        return;
    }
    
    if(plataforma === null){
        $('#combo_plataforma').focus();
        return;
    }
    
    var php_consulta  = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + "productos_agregar.php";
    var datos = 'codigo_1=' + codigo_1 +
        '&nombre_producto=' + nombre_producto +
        '&descripcion=' + descripcion +
        '&plataforma=' + plataforma +
        '&precio_venta=' + precio_venta +
        '&link_descarga='+ link_descarga
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
                    //var color = datos_tabla[i].color;
                    //var icono = datos_tabla[i].icono;
                    //var tiempo = datos_tabla[i].tiempo;
                }
                if(status === 'warning'){
                    mensaje_alerta(mensaje);
                    $('#txt_codigo_1').focus();
                }
                if(status === 'success'){
                    $('#modal_registro_nuevo').modal('toggle');
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

$('#btn_editar').click(function(){
    
    var n_seleccionados = tabla.rows('.selected').count();
    if(n_seleccionados === 0){
        mensaje_alerta('Selecciona un registro para continuar');
        return;
    }
    $('#modal_content').load('ajax/modal-content/productos_form.html');
    $('#lblModalHeader').text('Editar registro');
    $('#modal_registro_nuevo').modal('show');
    consulta_registro();
    accion = 'editar';
 
    
});

function consulta_registro() {
    try{
        var php_consulta = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + "productos_consultar.php";
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
                //alert(respuesta);
                var datos_tabla = JSON.parse(respuesta);
                for (var i = 0; i < datos_tabla.length; i++) {
                    
                    var codigo_1 = datos_tabla[i].codigo_1;
                    //var codigo_2 = datos_tabla[i].codigo_2;
                    var nombre_producto = datos_tabla[i].nombre_producto;
                    var descripcion = datos_tabla[i].descripcion;
                    var plataforma = datos_tabla[i].plataforma;
                    var link_descarga = datos_tabla[i].link_descarga;
                    var precio_venta = datos_tabla[i].precio_venta;
                            
                }
                $('#txt_codigo_1').prop('disabled', 'true');
                $('#txt_codigo_1').val(codigo_1);
                $('#txt_nombre_producto').val(nombre_producto);
                $('#txt_descripcion').val(descripcion);
                $('#combo_plataforma').val(plataforma);
                $('#txt_precio').val(precio_venta);
                $('#txt_link_descarga').val(link_descarga);
                
                ////ocultamos el loader
                oculta_loader();
                calcula_totales();
            },
            error: function (respuesta) {
                oculta_loader();
                var mensaje = 'No pude acceder al servidor en estos momentos, intente nuevamente y verifíque que el dispositivo tenga conexión a internet y si el problema persiste comuniquese con el administrador.';
                alert(respuesta);
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
        var codigo_1 = $('#txt_codigo_1').val().trim();
        var nombre_producto = $('#txt_nombre_producto').val().trim();
        var descripcion = $('#txt_descripcion').val().trim();
        var plataforma = $('#combo_plataforma').val();
        var precio_venta = $('#txt_precio').val();
        var link_descarga = $('#txt_link_descarga').val().trim();
    
        if(codigo_1 === '' || nombre_producto === '' || descripcion === '' || plataforma === null){
            mensaje_alerta('Capture los campos obligatorios');
        }
        if(codigo_1 === ''){
            $('#txt_codigo_1').val('').focus();
            return;
        }
        if(nombre_producto === ''){
            $('#txt_nombre_producto').val('').focus();
            return;
        }
        if(descripcion === ''){
            $('#txt_descripcion').val('').focus();
            return;
        }
        
        if(plataforma === null){
            $('#combo_plataforma').focus();
            return;
        }
    
        var php_consulta = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + "productos_editar.php";
        
        var datos = 'id_seleccionado=' + id_seleccionado +
            '&codigo_1=' + codigo_1 +
            '&nombre_producto=' + nombre_producto +
            '&descripcion=' + descripcion +
            '&plataforma=' + plataforma +
            '&precio_venta=' + precio_venta +
            '&link_descarga='+ link_descarga;
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
                    //var color = datos_tabla[i].color;
                    //var icono = datos_tabla[i].icono;
                    //var tiempo = datos_tabla[i].tiempo;
                }
                if(status === 'warning'){
                    mensaje_alerta(mensaje);
                    $('#txt_codigo_1').focus();
                }
                if(status === 'success'){
                    $('#modal_registro_nuevo').modal('toggle');
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
        var php_consulta ='https://licenciaspruebas.depilacionlasermexico.com/php/' + "productos_eliminar.php";
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
                mensaje_error(respuesta);
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
        var php_consulta = 'https://licenciaspruebas.depilacionlasermexico.com/php/' + "productos_catalogo.php";
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
                    
                    var id_producto = datos_tabla[i].id_producto;
                    var codigo_1 = datos_tabla[i].codigo_1;
                    var codigo_2 = datos_tabla[i].codigo_2;
                    var nombre_producto = datos_tabla[i].nombre_producto;
                    var descripcion = datos_tabla[i].descripcion;
                    var plataforma = datos_tabla[i].plataforma;
                    var link_descarga = datos_tabla[i].link_descarga;
                    var precio_venta = datos_tabla[i].precio_venta;
                    var eliminado = datos_tabla[i].eliminado;
                    var fecha_registro = datos_tabla[i].fecha_registro;
                    /*             
                    var forzado = '<span class="label label-warning">' + datos_tabla[i].forzado + '</span>';
                    */
                    tabla.row.add(
                        [
                            '',
                            id_producto, 
                            codigo_1, 
                            //codigo_2,
                            nombre_producto, 
                            descripcion,  
                            plataforma,
                            link_descarga,
                            precio_venta
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
    $('#lbl_n_registros').html('Productos <span class="txt-color-blue"><i class="fa fa-cubes"></i> ' + n_filas + '</span>');
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

