<?php
try {
	//session_start();
    //header("Access-Control-Allow-Origin: *");
    //header("Access-Control-Allow-Methods: *");
    //header("Access-Control-Allow-Headers: *");
	include_once('conexion.php');
    
	date_default_timezone_set('America/Mexico_City'); //establecemos la zona horaria de México
	    
    $codigo_cliente = $_POST["codigo_cliente"];
	$empresa = $_POST["empresa"];
	$nombres = $_POST["nombres"];
    $apellidos = $_POST["apellidos"];
	$telefono = $_POST["telefono"];
    $email = $_POST["email"];
    $calle = $_POST["calle"];
    $colonia = $_POST["colonia"];
    $cp = $_POST["cp"];
    $pais = $_POST["pais"];
	$fecha = date("Y-m-d");
	$hora = date("H:i:s");
    $respuesta = array();
    
	$charset = $conexion->query("SET NAMES 'utf8'");
    
	$query0 = "SELECT codigo_cliente FROM clientes WHERE codigo_cliente='".$codigo_cliente."' AND eliminado='0';";
	$consulta_duplicado = $conexion->query($query0);
	if ($consulta_duplicado->num_rows > 0)
	{
        $datos[] = array(
            "status" => "warning",
            "mensaje" => "El código ".$codigo_cliente." ya está asignado a otro cliente, verifíquelo por favor.",
            "color" => "#739E73",
            "icono" => "fa fa-check-square-o bounce animated",
            "tiempo" => "5500"
        );
        exit(json_encode($datos, JSON_PRETTY_PRINT));
	}
	
    $query1 = "INSERT INTO clientes (codigo_cliente, empresa, nombres, apellidos, email, telefono_1, direccion, colonia, cp, pais) VALUES ('" . $codigo_cliente . "', '" . $empresa . "', '" . $nombres . "', '" . $apellidos . "', '".$email."', '" . $telefono . "', '" . $calle . "', '".$colonia."', '".$cp."', '".$pais."');";
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