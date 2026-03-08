<?php
try {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *"); 
    header("Access-Control-Allow-Headers: *");
	include_once('conexion.php');
    date_default_timezone_set('America/Mexico_City');
    
    $id_seleccionado = $_POST['id_seleccionado'];
    $datos = array();
    
    $query = "SELECT productos.codigo_1, productos.codigo_2, productos.nombre_producto, productos.descripcion, productos.plataforma, productos.link_descarga, productos.precio_venta, productos.fecha_registro FROM productos WHERE productos.id_producto='".$id_seleccionado."';";
    $consulta = $conexion->query($query);
    if ($consulta->num_rows > 0)
        {
        $fila = $consulta->fetch_assoc();
        $codigo_1 = utf8_encode($fila["codigo_1"]);
		$codigo_2 = utf8_encode($fila["codigo_2"]);
        $nombre_producto = utf8_encode($fila["nombre_producto"]);
        $descripcion = utf8_encode($fila["descripcion"]);
        $plataforma = utf8_encode($fila["plataforma"]);
        $link_descarga = utf8_encode($fila["link_descarga"]);
        $precio_venta = utf8_encode($fila["precio_venta"]);
        $fecha_registro = utf8_encode($fila["fecha_registro"]);

        $datos[] = array(
            'codigo_1' => $codigo_1, 
            'codigo_2' => $codigo_2, 
            'nombre_producto' => $nombre_producto, 
            'descripcion' => $descripcion, 
            'plataforma' => $plataforma,
            'link_descarga' => $link_descarga,
            'precio_venta' => $precio_venta 
            //'fecha_registro' => $fecha_registro 
        );
    }
    echo json_encode($datos ,JSON_PRETTY_PRINT);
}
catch (Exception $e) {
    echo 'error';
    //echo 'Error|'.$e->getMessage().'|#A65858|fa fa-times-circle shake animated|5500|error';
}
?>