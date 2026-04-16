<?php
try
{
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *");
    header("Access-Control-Allow-Headers: *");
	include_once('conexion.php');
    
    $codigo_activacion = $_POST["codigo_activacion"];
    $datos = array();
    $i=0;

	$query0 = "SELECT dispositivos_activados.id_dispositivo_activado, 
    dispositivos_activados.cliente, 
dispositivos_activados.nombre_dispositivo, 
dispositivos_activados.tipo_dispositivo, 
dispositivos_activados.plataforma_dispositivo, 
dispositivos_activados.modelo_dispositivo, 
dispositivos_activados.version_dispositivo, 
dispositivos_activados.fabricante_dispositivo, 
dispositivos_activados.id_dispositivo,
dispositivos_activados.llave_activacion, 
dispositivos_activados.fecha_activacion, 
dispositivos_activados.fecha_fin_vigencia, 
dispositivos_activados.n_activaciones, 
IF(dispositivos_activados.lista_negra = 0, 'NO', 'SI') AS lista_negra, 
IF(dispositivos_activados.eliminado = 0, 'NO', 'SI') AS eliminado  
FROM dispositivos_activados 
WHERE dispositivos_activados.codigo_activacion='".$codigo_activacion."' 
ORDER BY dispositivos_activados.id_dispositivo_activado DESC;";
    $resultado = $conexion->query($query0);
    //$datos = $resultado->fetch_all(MYSQLI_ASSOC);
    //echo json_encode($datos);
    
	if ($resultado->num_rows > 0)
	{
		// datos de cada columna
		while($fila = $resultado->fetch_assoc())
		{
            $i++;
            $id_dispositivo_activado = utf8_encode($fila["id_dispositivo_activado"]);
            $cliente = utf8_encode($fila["cliente"]);
            $nombre_dispositivo = utf8_encode($fila["nombre_dispositivo"]);
            $tipo_dispositivo = utf8_encode($fila["tipo_dispositivo"]);
            $plataforma_dispositivo = utf8_encode($fila["plataforma_dispositivo"]);
            $modelo_dispositivo = utf8_encode($fila["modelo_dispositivo"]);
            $version_dispositivo = utf8_encode($fila["version_dispositivo"]);
            $fabricante_dispositivo = utf8_encode($fila["fabricante_dispositivo"]);
            $id_dispositivo = utf8_encode($fila["id_dispositivo"]); 
            $llave_activacion = utf8_encode($fila["llave_activacion"]);  
            $fecha_activacion = utf8_encode($fila["fecha_activacion"]);
            $fecha_fin_vigencia = utf8_encode($fila["fecha_fin_vigencia"]);
            $n_activaciones = utf8_encode($fila["n_activaciones"]);
            $lista_negra = utf8_encode($fila["lista_negra"]);
            $eliminado = utf8_encode($fila["eliminado"]);
          

            $datos[] = array(
                "i" => $i,
                "id_dispositivo_activado" => $id_dispositivo_activado, 
                "cliente" => $cliente, 
                "nombre_dispositivo" => $nombre_dispositivo,
                "tipo_dispositivo" => $tipo_dispositivo,
                "plataforma_dispositivo" => $plataforma_dispositivo, 
                "modelo_dispositivo" => $modelo_dispositivo,
                "version_dispositivo" => $version_dispositivo,
                "fabricante_dispositivo" => $fabricante_dispositivo, 
                "id_dispositivo" => $id_dispositivo, 
                "llave_activacion" => $llave_activacion,
                "fecha_activacion" => $fecha_activacion, 
                "fecha_fin_vigencia" => $fecha_fin_vigencia, 
                "n_activaciones" => $n_activaciones,
                "lista_negra" => $lista_negra, 
                "eliminado" => $eliminado 
            );
        }
    }
    echo json_encode($datos, JSON_PRETTY_PRINT);
}
catch (Exception $e) {
	echo $e->getMessage();
}
?>