<?php
try {
	//session_start();
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *");
    header("Access-Control-Allow-Headers: *");
	include_once('conexion.php');
    
	date_default_timezone_set('America/Mexico_City'); //establecemos la zona horaria de México
    
    $id_cliente = $_POST["id_cliente"];
    $codigo_cliente = $_POST["codigo_cliente"];
	$codigo_producto = $_POST["codigo_producto"];
	$tipo_licencia = $_POST["tipo_licencia"];
    $precio_venta = $_POST["precio_venta"];
	$cantidad = $_POST["cantidad"];
    $importe = $_POST["importe"];
    $comentarios = $_POST["comentarios"];
    $cadena = $_POST["cadena"];
	$fecha = date("Y-m-d");
	$hora = date("H:i:s");
    $respuesta = array();
    
	$charset = $conexion->query("SET NAMES 'utf8'");
    
	$query0 = "SELECT codigo_producto FROM asignacion_licencias WHERE cliente='".$id_cliente."' AND codigo_producto='" . $codigo_producto . "' AND cadena='".$cadena."';";
	$consulta_duplicado = $conexion->query($query0);
	if ($consulta_duplicado->num_rows > 0)
	{
        $query1 = "UPDATE asignacion_licencias SET n_licencias=n_licencias+'" . $cantidad . "' WHERE cliente='".$id_cliente."' AND codigo_producto='" . $codigo_producto . "' AND cadena='".$cadena."';";

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
	}
	
    $query2 = "INSERT INTO asignacion_licencias (cliente, codigo_producto, n_licencias, tipo_licencia, codigo_activacion, fecha, precio, importe, comentarios) VALUES ('" . $id_cliente . "', '" . $codigo_producto . "', '" . $cantidad . "', '" . $tipo_licencia . "', '".$cadena."', '" . $fecha . " " . $hora . "', '".$precio_venta."', '".$importe."', '".$comentarios."');";
	if($conexion->query($query2)){
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