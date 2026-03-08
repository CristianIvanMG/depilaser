<?php
try
{
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *");
    header("Access-Control-Allow-Headers: *");
	include_once('conexion.php');

    //$id_usuario = $_POST['id_usuario'];
    $datos = array();
    $i = 0;
	$query0 = "SELECT id_producto,inventario,codigo_1,codigo_2,codigo_3,descripcion,modelo,marca,categoria,subcategoria,subcategoria_2,precio_compra,precio,cantidad_teorica,unidad_medida,seriado,IF(catalogos.forzado='1', 'SI', 'NO') AS forzado,cantidad_teorica*precio AS importe_teorico FROM catalogos WHERE inventario='7' AND eliminado='0';";

    $resultado = $conexion->query($query0);
    //$datos[] = $resultado->fetch_assoc();
    //$datos[] = $resultado->fetch_all(MYSQLI_ASSOC);
    //echo json_encode($datos);    
	if ($resultado->num_rows > 0)
    {
        while($fila = $resultado->fetch_assoc())
        {
            $i++;
            $id_producto = utf8_encode($fila["id_producto"]);
            $codigo_1 = utf8_encode($fila["codigo_1"]);
            $codigo_2 = utf8_encode($fila["codigo_2"]);
            $codigo_3 = utf8_encode($fila["codigo_3"]);
            $descripcion = utf8_encode($fila["descripcion"]);
            $modelo = utf8_encode($fila["modelo"]);
            $marca = utf8_encode($fila["marca"]);
            $categoria = utf8_encode($fila["categoria"]);
            $subcategoria = utf8_encode($fila["subcategoria"]);
            $subcategoria_2 = utf8_encode($fila["subcategoria_2"]);
            $precio_compra = utf8_encode($fila["precio_compra"]);
            $precio = utf8_encode($fila["precio"]);
            $cantidad_teorica = utf8_encode($fila["cantidad_teorica"]);
            $unidad_medida = utf8_encode($fila["unidad_medida"]);
            $seriado = utf8_encode($fila["seriado"]);
            $forzado = utf8_encode($fila["forzado"]);
            $importe_teorico = utf8_encode($fila["importe_teorico"]);

            $datos[] = array(
               'i' => $i,
               'id_producto' => $id_producto,
               'codigo_1' => $codigo_1,
               'codigo_2' => $codigo_2,
               'codigo_3' => $codigo_3,
               'descripcion' => $descripcion,
               'modelo' => $modelo,
               'marca' => $marca,
               'categoria' => $categoria,
               'subcategoria' => $subcategoria,
               'subcategoria_2' => $subcategoria_2,
                'precio_compra' => $precio_compra,
                'precio' => $precio,
                'cantidad_teorica' => $cantidad_teorica,
                'unidad_medida' => $unidad_medida,
                'seriado' => $seriado,
                'forzado' => $forzado,
                'importe_teorico' => $importe_teorico
               
           );
        }
    }
    echo json_encode($datos, JSON_PRETTY_PRINT);
    
}
catch (Exception $e) {
	echo $e->getMessage();
}
?>