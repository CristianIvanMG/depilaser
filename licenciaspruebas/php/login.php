<?php
try {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *"); 
    header("Access-Control-Allow-Headers: *");
	include_once('conexion.php');

    $usuario = $_POST["usuario"];
    $password = $_POST["password"];
    $datos = array();
    
    $query = "SELECT id_usuario, nombres, apellidos, activo FROM usuarios WHERE nombre_usuario='".$usuario."' AND password='".$password."' AND eliminado='0';";
    $consulta = $conexion->query($query);
    if ($consulta->num_rows > 0){
        
        $fila = $consulta->fetch_assoc();
        $id_usuario = utf8_encode($fila["id_usuario"]);
		$nombres = utf8_encode($fila["nombres"]);
        $apellidos = utf8_encode($fila["apellidos"]);
        $activo = utf8_encode($fila["activo"]);
      
        $datos[] = array(
            'id_usuario' => $id_usuario, 
            'nombres' => $nombres, 
            'apellidos' => $apellidos,
            'activo' => $activo
        );
    }
    echo json_encode($datos ,JSON_PRETTY_PRINT);
}
catch (Exception $e) {
    echo $e->getMessage();
}
?>