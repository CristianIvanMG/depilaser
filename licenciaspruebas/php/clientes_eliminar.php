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
	
    $query1 = "UPDATE clientes SET eliminado='1' WHERE id_cliente='".$id_seleccionado."'";
    
	if($conexion->query($query1)){
         $respuesta[] = array(
            "status" => "success",
            "mensaje" => "Los datos fueron guardados exitosamente.",
            "color" => "#739E73",
            "icono" => "fa fa-check-square-o bounce animated",
            "tiempo" => "4000"               
        );
        echo json_encode($respuesta, JSON_PRETTY_PRINT);
        return;
    }
    
     $respuesta[] = array(
            "status" => "error",
            "mensaje" => "Algo salió mal, intente de nuevo y si el problema persiste contacte al administrador.",
            "color" => "#A65858",
            "icono" => "fa fa-times-circle shake animated",
            "tiempo" => "5500"
    );
    
	echo json_encode($respuesta, JSON_PRETTY_PRINT);
    return;
}
catch (Exception $e) {
	echo 'Error|'.$e->getMessage().'|#A65858|fa fa-times-circle shake animated|5500|error';
}
?>