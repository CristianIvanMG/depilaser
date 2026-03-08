//información de la sesion iniciada
var id_usuario_logueado = 0;
var usuario_logueado = '';
var nombre_usuario_logueado = '';
var es_administrador = 1;

var tabla_sesion = localStorage.getItem('sesiones');

function elimina_bd(){
    localStorage.removeItem('sesiones');
}

//Creamos y configuramos la base de de datos en caso de que no exista
function crea_bd() {
    //elimina_bd();
    //guardamos información de sesiones, utilizamos localstorage con fines de pruebas :P
    if(tabla_sesion === null){
        try{
            tabla_sesion = [ { 
                "id_sesion":1,
                "id_usuario":id_usuario_logueado,
                "usuario":usuario_logueado,
                "nombre_usuario":nombre_usuario_logueado,
                "es_administrador":es_administrador
            } ];
            localStorage.setItem('sesiones', JSON.stringify(tabla_sesion));
        }
        catch(err) {
            alert(err.message); 
        }
    }
}

function consulta_datos_sesiones(){
    try{
        var datos_tabla = JSON.parse(localStorage.getItem('sesiones'));
        for (var i = 0; i < datos_tabla.length; i++) {
            var id_sesion = datos_tabla[i].id_sesion;
            if(id_sesion === 1){
                id_usuario_logueado = datos_tabla[i].id_usuario;
                usuario_logueado = datos_tabla[i].usuario;
                nombre_usuario_logueado = datos_tabla[i].nombre_usuario;
                es_administrador = datos_tabla[i].es_administrador;
                //alert(id_sesion + '\n' + id_usuario_logueado + '\n' + usuario_logueado + '\n' + nombre_usuario_logueado +  '\n' + es_administrador);
            }
        }
    }
    catch(err) {
        alert(err.message); 
    }
}

//    
function guarda_datos_sesion(id_usuario, usuario, nombre_usuario, administrador){
    try{
        var datos_tabla = JSON.parse(localStorage.sesiones);
        for (var i = 0; i < datos_tabla.length; i++) {
            if(datos_tabla[i].id_sesion === 1){
                datos_tabla[i].id_usuario = id_usuario;
                datos_tabla[i].usuario = usuario;
                datos_tabla[i].nombre_usuario = nombre_usuario;
                datos_tabla[i].es_administrador = administrador;
                break;  //salimos del loop al encontrar la información
            }
        }
        localStorage.setItem('sesiones', JSON.stringify(datos_tabla));
    }
    catch (err) {
        alert(err.message);
    }
}