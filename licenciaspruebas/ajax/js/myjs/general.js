var version_app = '1.0.0';
var modo_prueba = 0; 
var nombre_servidor = 'https://licenciaspruebas.depilacionlasermexico.com/';
var ip_servidor = '';

//alert(ruta_php);
//información de la sesion iniciada
var id_usuario_logueado = 0;
var usuario_logueado = '';
var nombre_usuario_logueado = '';
var es_administrador = 0;
var modo_online = 0;

//mostramos el loader
function muestra_loader() {
    $('#modal_procesando').modal('show');
}

//ocultamos el loader
function oculta_loader() {
    $('#modal_procesando').modal('hide');
}

function mensaje_alerta(mensaje){
    $.smallBox({
			title : 'Atención',
			content : mensaje,
			color : '#C79121',
			icon : 'fa fa-warning swing animated',
			timeout : 4000
		});
}

function mensaje_exito(mensaje){
    $.smallBox({
			title : 'Atención',
			content : mensaje,
			color : '#739E73',
			icon : 'fa fa-check-square-o swing animated',
			timeout : 4000
		});
}

function mensaje_error(mensaje){
    $.smallBox({
			title : 'Error',
			content : mensaje,
			color : '#A65858',
			icon : 'fa fa-times-circle swing animated',
			timeout : 5000
		});
}

//obtenemos la fecha deseada sumando o restando los días deseados
//Ej.: para obtener la fecha de hoy solo ejecutamos str_fecha(0);
function str_fecha(n) {
    var now = new Date($.now());
    if(now.getDate() + n < 1 ){
        n = 0;
    }
    var anio = now.getFullYear();
    var mes = now.getMonth() + 1;
    var dia = now.getDate() + n;
    if (mes < 10) { mes = '0' + mes; }
    if (dia < 10) { dia = '0' + dia; }
    return fecha = anio + '-' + mes + '-' + dia;

}

function str_hora(){
    
    var now = new Date($.now());
    var hora = now.getHours();
    if (hora < 10) { hora = '0' + hora; }
    var minutos = now.getMinutes();
    if (minutos < 10) { minutos = '0' + minutos; }
    var segundos = now.getSeconds();
    if (segundos < 10) { segundos = '0' + segundos; }
    return hora =  hora + ':' + minutos + ':' + segundos;
    
}

//al presionar la tecla Enter en un campo con la clase focus cambie el cursor
//cambie al siguiente campo del formulario con la clase focus
$('.focus').keydown(function (e) {
    if (e.which === 13) {
        var index = $('.focus').index(this) + 1;
        $('.focus').eq(index).focus();
        return false;
    }
});