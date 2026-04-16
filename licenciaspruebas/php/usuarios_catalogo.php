<?php
try
{
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *");
    header("Access-Control-Allow-Headers: *");
	include_once('conexion.php');
    
    $datos = array();
    $i=0;

	$query0 = "SELECT usuarios.id_usuario, usuarios.nombre_usuario, usuarios.nombres, usuarios.apellidos, usuarios.email, IF(usuarios.activo = 0, 'NO', 'SI') AS activo, IF(usuarios.administrador = 0, 'NO', 'SI') AS administrador   
FROM usuarios WHERE usuarios.root='0' AND usuarios.eliminado='0';";
    $resultado = $conexion->query($query0);
    //$datos = $resultado->fetch_all(MYSQLI_ASSOC);
    //echo json_encode($datos);
    
	if ($resultado->num_rows > 0)
	{
		// datos de cada columna
		while($fila = $resultado->fetch_assoc())
		{
            $i++;
            $id_usuario = utf8_encode($fila["id_usuario"]);
            $nombres = utf8_encode($fila["nombres"]);
            $apellidos = utf8_encode($fila["apellidos"]);
            $email = utf8_encode($fila["email"]);
            $nombre_usuario = utf8_encode($fila["nombre_usuario"]);
            $activo = utf8_encode($fila["activo"]);
            $administrador = utf8_encode($fila["administrador"]);

             $datos[] = array(
                 "i" => $i,
                 "id_usuario" => $id_usuario,
                 "nombres" => $nombres,
                 "apellidos" => $apellidos,
                 "email" => $email,
                 "nombre_usuario" => $nombre_usuario,
                 "email" => $email,
                 "activo" => $activo,
                 "administrador" => $administrador
            );
        }
    }
    echo json_encode($datos, JSON_PRETTY_PRINT);
}
catch (Exception $e) {
	echo $e->getMessage();
}
?>