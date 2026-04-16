<?php
try {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *"); 
    header("Access-Control-Allow-Headers: *");
	include_once('conexion.php');
    date_default_timezone_set('America/Mexico_City');
    
    $id_seleccionado = $_POST['id_seleccionado'];
    $datos = array();
    
    $query = "SELECT * FROM clientes WHERE id_cliente='".$id_seleccionado."';";
    $consulta = $conexion->query($query);
    if ($consulta->num_rows > 0){
        $fila = $consulta->fetch_assoc();
        
        $codigo_cliente = utf8_encode($fila["codigo_cliente"]);
		$empresa = utf8_encode($fila["empresa"]);
        $nombres = utf8_encode($fila["nombres"]);
        $apellidos = utf8_encode($fila["apellidos"]);
        $email = utf8_encode($fila["email"]);
        $telefono_1 = utf8_encode($fila["telefono_1"]);
        $direccion = utf8_encode($fila["direccion"]);
        $colonia = utf8_encode($fila["colonia"]);
        $cp = utf8_encode($fila["cp"]);
        $pais = utf8_encode($fila["pais"]);

        $datos[] = array(
            'codigo_cliente' => $codigo_cliente, 
            'empresa' => $empresa, 
            'nombres' => $nombres, 
            'apellidos' => $apellidos, 
            'email' => $email,
            'telefono_1' => $telefono_1,
            'direccion' => $direccion,  
            'colonia' => $colonia,  
            'cp' => $cp,  
            'pais' => $pais 
        );
    }
    echo json_encode($datos ,JSON_PRETTY_PRINT);
}
catch (Exception $e) {
    echo 'error';
    //echo 'Error|'.$e->getMessage().'|#A65858|fa fa-times-circle shake animated|5500|error';
}
?>