<?php
try {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *"); 
    header("Access-Control-Allow-Headers: *");
	include_once('conexion.php');
    date_default_timezone_set('America/Mexico_City');
    
    $id_seleccionado = $_POST['id_seleccionado'];
    $datos = array();
    
    $query = "SELECT * FROM usuarios WHERE id_usuario='".$id_seleccionado."';";
    $consulta = $conexion->query($query);
    if ($consulta->num_rows > 0){
        $fila = $consulta->fetch_assoc();
        
        $nombres = utf8_encode($fila["nombres"]);
		$apellidos = utf8_encode($fila["apellidos"]);
        $email = utf8_encode($fila["email"]);
        $nombre_usuario = utf8_encode($fila["nombre_usuario"]);
        $activo = utf8_encode($fila["activo"]);
        $administrador = utf8_encode($fila["administrador"]);
        
        $datos[] = array(
            'nombres' => $nombres, 
            'apellidos' => $apellidos, 
            'email' => $email, 
            'nombre_usuario' => $nombre_usuario, 
            'activo' => $activo,
            'administrador' => $administrador
        );
    }
    echo json_encode($datos ,JSON_PRETTY_PRINT);
}
catch (Exception $e) {
    echo $e->getMessage();
}
?>