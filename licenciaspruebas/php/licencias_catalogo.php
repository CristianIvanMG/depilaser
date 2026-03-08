<?php
try
{
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *");
    header("Access-Control-Allow-Headers: *");
	include_once('conexion.php');
    
    $datos = array();
    $i=0;

	$query0 = "SELECT asignacion_licencias.id, asignacion_licencias.cliente, clientes.empresa, CONCAT(clientes.nombres, ' ', clientes.apellidos) AS contacto, asignacion_licencias.codigo_producto, asignacion_licencias.codigo_activacion, productos.nombre_producto, asignacion_licencias.n_licencias, licencia_tipos.tipo_licencia, asignacion_licencias.fecha 
FROM clientes, asignacion_licencias, licencia_tipos, productos    
WHERE clientes.id_cliente=asignacion_licencias.cliente 
AND asignacion_licencias.tipo_licencia=licencia_tipos.id_tipo_licencia 
AND asignacion_licencias.codigo_producto = productos.codigo_1 
ORDER BY asignacion_licencias.id DESC;";
    
    $resultado = $conexion->query($query0);
    //$datos = $resultado->fetch_all(MYSQLI_ASSOC);
    //echo json_encode($datos);
	if ($resultado->num_rows > 0)
	{
		// datos de cada columna
		while($fila = $resultado->fetch_assoc())
		{
            $i++;
            $id = utf8_encode($fila["id"]);
            $cliente = utf8_encode($fila["cliente"]);
            $empresa = utf8_encode($fila["empresa"]);
            $contacto = utf8_encode($fila["contacto"]);
            $codigo_producto = utf8_encode($fila["codigo_producto"]);
            $codigo_activacion = utf8_encode($fila["codigo_activacion"]);
            $nombre_producto = utf8_encode($fila["nombre_producto"]);
            $n_licencias = utf8_encode($fila["n_licencias"]);
            $tipo_licencia = utf8_encode($fila["tipo_licencia"]);
            $fecha = utf8_encode($fila["fecha"]);

            $datos[] = array(
                "i" => $i,
                "id" => $id,
                "cliente" => $cliente,
                "empresa" => $empresa,
                "contacto" => $contacto, 
                "codigo_producto" => $codigo_producto,
                "codigo_activacion" => $codigo_activacion, 
                "nombre_producto" => $nombre_producto,
                "n_licencias" => $n_licencias,
                "tipo_licencia" => $tipo_licencia, 
                "fecha" => $fecha 
            );
        }
    }
    echo json_encode($datos, JSON_PRETTY_PRINT);
}
catch (Exception $e) {
	echo $e->getMessage();
}
?>