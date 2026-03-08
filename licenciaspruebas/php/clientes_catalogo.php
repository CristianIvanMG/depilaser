<?php
try
{
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *");
    header("Access-Control-Allow-Headers: *");
	include_once('conexion.php');
    
    $datos = array();
    $i=0;

	$query0 = "SELECT * FROM clientes WHERE eliminado='0' ORDER BY codigo_cliente ASC;";
    $resultado = $conexion->query($query0);
    //$datos = $resultado->fetch_all(MYSQLI_ASSOC);
    //echo json_encode($datos);
    
	if ($resultado->num_rows > 0)
	{
		// datos de cada columna
		while($fila = $resultado->fetch_assoc())
		{
            $i++;
            $id_cliente = utf8_encode($fila["id_cliente"]);
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
                 "i" => $i,
                 "id_cliente" => $id_cliente,
                 "codigo_cliente" => $codigo_cliente,
                 "empresa" => $empresa,
                 "nombres" => $nombres,
                 "apellidos" => $apellidos,
                 "email" => $email,
                 "telefono_1" => $telefono_1,
                 "direccion" => $direccion,
                 "colonia" => $colonia,
                 "cp" => $cp,
                 "pais" => $pais
               
            );
        }
    }
    echo json_encode($datos, JSON_PRETTY_PRINT);
}
catch (Exception $e) {
	echo $e->getMessage();
}
?>