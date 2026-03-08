<?php
error_reporting(0);
header("Access-Control-Allow-Origin: *"); 
header("Access-Control-Allow-Methods: *"); 
header("Access-Control-Allow-Headers: *");

$modo_pruebas = 0;
$servidor = "149.100.151.1";
$usuario = "u242983485_cmorales";
$bd = "u242983485_Licencias_CM";
$password =  "m4a1g36C";

if($modo_pruebas == 1)
{
    $servidor = "localhost";
    $usuario = "root";
    $bd = "intelisy_licencias";
    $password = "";
}

defined("DB_HOST") ? null : define("DB_HOST", $servidor);
defined("DB_USER") ? null : define("DB_USER", $usuario);
defined("DB_NAME") ? null : define("DB_NAME", $bd);
defined("DB_PASSWORD") ? null : define("DB_PASSWORD", $password);

//$conexion = new mysqli($servidor, $usuario, $bd, $password);
$conexion = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
// comprobamos la conexión
if ($conexion->connect_errno) 
{
    //echo '<script>alert("'.$conexion->connect_error.'")</script>';
	exit('<div class="alert alert-danger"><a href="#" class="close" data-dismiss="alert" aria-label="close"></a><strong><i class="glyphicon glyphicon-warning-sign"></i> La conexi&oacute;n con el servidor ha fallado: '.$conexion->connect_error.'
	</strong><br />Intente nuevamente y si el problema persiste llame al administrador del sistema.</div>');
}
?>
