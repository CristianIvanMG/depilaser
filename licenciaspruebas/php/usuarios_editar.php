<?php
try {
	//session_start();
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *");
    header("Access-Control-Allow-Headers: *");
	include_once('conexion.php');
    
	date_default_timezone_set('America/Mexico_City'); //establecemos la zona horaria de México
	
    $id_seleccionado = $_POST["id_seleccionado"];
    $nombres = $_POST["nombres"];
	$apellidos = $_POST["apellidos"];
	$email = $_POST["email"];
    $usuario = $_POST["usuario"];
	$password = $_POST["password"];
    $activo = $_POST["activo"];
	$fecha = date("Y-m-d");
	$hora = date("H:i:s");
    $respuesta = array();
    
	$charset = $conexion->query("SET NAMES 'utf8'");
    
    $query0 = "SELECT nombre_usuario FROM usuarios WHERE nombre_usuario='".$usuario."' AND id_usuario!='".$id_seleccionado."' AND eliminado='0';";
	$consulta_duplicado = $conexion->query($query0);
	if ($consulta_duplicado->num_rows > 0)
	{
        $respuesta[] = array(
            "status" => "warning",
            "mensaje" => "El nombre de usuario ".$usuario." ya está asignado a otro registro, verifíquelo por favor.", 
            "color" => "#739E73",
            "icono" => "fa fa-check-square-o bounce animated",
            "tiempo" => "5500"
        );
        exit(json_encode($respuesta, JSON_PRETTY_PRINT));
	}
    
    $query1 = "UPDATE usuarios SET nombres='" . $nombres . "', apellidos='" . $apellidos . "', email='" . $email . "', nombre_usuario='" . $usuario . "', activo='" . $activo . "' WHERE id_usuario='".$id_seleccionado."';";
    
    if($password != ""){
        $query1 = "UPDATE usuarios SET nombres='" . $nombres . "', apellidos='" . $apellidos . "', email='" . $email . "', nombre_usuario='" . $usuario . "', password='" . $password . "', activo='" . $activo . "' WHERE id_usuario='".$id_seleccionado."';";
    }
    
	if($conexion->query($query1)){
         $respuesta[] = array(
            "status" => "success",
            "mensaje" => "Los datos fueron guardados exitosamente.",
            "color" => "#739E73",
            "icono" => "fa fa-check-square-o bounce animated",
            "tiempo" => "4000"               
        );
        exit(json_encode($respuesta, JSON_PRETTY_PRINT));
    }
    
     $respuesta[] = array(
            "status" => "error",
            "mensaje" => "Algo salió mal, intente de nuevo y si el problema persiste contacte al administrador.",
            "color" => "#A65858",
            "icono" => "fa fa-times-circle shake animated",
            "tiempo" => "5500"               
    );
    
	exit(json_encode($respuesta, JSON_PRETTY_PRINT));
}
catch (Exception $e) {
    
    $respuesta[] = array(
            "status" => "error",
            "mensaje" => $e->getMessage(),
            "color" => "#A65858",
            "icono" => "fa fa-times-circle shake animated",
            "tiempo" => "5500"               
    );
	exit(json_encode($respuesta, JSON_PRETTY_PRINT));
    
}
?>