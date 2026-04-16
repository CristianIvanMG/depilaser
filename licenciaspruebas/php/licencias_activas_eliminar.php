<?php
try {
	//session_start();
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *");
    header("Access-Control-Allow-Headers: *");
	include_once('conexion.php');
    
	date_default_timezone_set('America/Mexico_City'); //establecemos la zona horaria de México
    $id_seleccionado = $_POST['id_seleccionado'];
    $respuesta = array();
	$charset = $conexion->query("SET NAMES 'utf8'");
    
    $query0 = "SELECT id_dispositivo_activado FROM dispositivos_activados WHERE id_dispositivo_activado='".$id_seleccionado."' AND eliminado='1';";
	$consulta = $conexion->query($query0);
	if ($consulta->num_rows > 0)
	{
        $datos[] = array(
            "status" => "warning",
            "mensaje" => "La licencia seleccionada ya fue eliminada anteriormente del sistema.",
            "color" => "#739E73",
            "icono" => "fa fa-check-square-o bounce animated",
            "tiempo" => "5500"
        );
        exit(json_encode($datos, JSON_PRETTY_PRINT));
	}
	
    $query1 = "UPDATE dispositivos_activados SET eliminado='1' WHERE id_dispositivo_activado='".$id_seleccionado."'";
    
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
	echo 'Error|'.$e->getMessage().'|#A65858|fa fa-times-circle shake animated|5500|error';
}
?>