<?php
try
{
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *");
    header("Access-Control-Allow-Headers: *");
	include_once('conexion.php');
    
    $datos = array();
    $i=0;

	$query0 = "SELECT productos.id_producto, productos.codigo_1, productos.codigo_2, productos.nombre_producto, productos.descripcion, productos.plataforma, productos.link_descarga, productos.precio_venta, productos.eliminado, productos.fecha_registro  
FROM productos 
WHERE productos.eliminado='0' ORDER BY codigo_1 ASC;";
    $resultado = $conexion->query($query0);
    //$datos = $resultado->fetch_all(MYSQLI_ASSOC);
    //echo json_encode($datos);
    
	if ($resultado->num_rows > 0)
	{
		// datos de cada columna
		while($fila = $resultado->fetch_assoc())
		{
            $i++;
            $id_producto = utf8_encode($fila["id_producto"]);
            $codigo_1 = utf8_encode($fila["codigo_1"]);
            $codigo_2 = utf8_encode($fila["codigo_2"]);
            $nombre_producto = utf8_encode($fila["nombre_producto"]);
            $descripcion = utf8_encode($fila["descripcion"]);
            $plataforma = utf8_encode($fila["plataforma"]);
            $link_descarga = utf8_encode($fila["link_descarga"]);
            $precio_venta = utf8_encode($fila["precio_venta"]);
            
            
             $datos[] = array(
                 "i" => $i,
                 "id_producto" => $id_producto,
                 "codigo_1" => $codigo_1,
                 "codigo_2" => $codigo_2,
                 "nombre_producto" => $nombre_producto,
                 "descripcion" => $descripcion,
                 "plataforma" => $plataforma,
                 "link_descarga" => $link_descarga,
                 "precio_venta" => $precio_venta 
               
            );
        }
    }
    echo json_encode($datos, JSON_PRETTY_PRINT);
}
catch (Exception $e) {
	echo $e->getMessage();
}
?>