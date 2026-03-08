<?php 
    if(isset($_GET['id_cliente'])&& isset($_GET["codigo_activacion"])&& isset($_GET["nombre_dispositivo"])&& isset($_GET["id_dispositivo"])&& isset($_GET["llave"])&& isset($_GET["licencia_dispositivo"]) && isset($_GET["codigo_producto"])&& isset($_GET["fecha_dispositivo"])&& isset($_GET["fecha_fin_vigencia"])&& isset($_GET["tipo_dispositivo"])&& isset($_GET["plataforma_dispositivo"])&& isset($_GET["version_dispositivo"]))
    {
      $id_cliente = $_GET['id_cliente'];
      $codigo_activacion = $_GET['codigo_activacion'];
      $nombre_dispositivo = $_GET['nombre_dispositivo'];
      $id_dispositivo = $_GET['id_dispositivo'];
      $llave = $_GET['llave'];
      $licencia_dispositivo = $_GET['licencia_dispositivo'];
      $codigo_producto = $_GET['codigo_producto'];
      $fecha_dispositivo = $_GET['fecha_dispositivo'];
      $fecha_fin_vigencia = $_GET['fecha_fin_vigencia'];
      $tipo_dispositivo = $_GET['tipo_dispositivo'];
      $plataforma_dispositivo = $_GET['plataforma_dispositivo'];
      $version_dispositivo = $_GET['version_dispositivo'];
     
        $servername = "109.106.250.106";
        $database = "intelisy_licencias";
        $username = "intelisy_root";
        $password = "Lmenss130813**";
        
      $conn = mysqli_connect($servername, $username, $password, $database);
      if (!$conn) 
      {
         $datos=array();
                 array_push($datos,array(
                 'error'=>"1",'mensaje'=>mysqli_connect_error()));
                  echo utf8_encode(json_encode($datos));
      }
      $sql = "INSERT INTO dispositivos_activados(cliente, codigo_activacion,nombre_dispositivo,id_dispositivo,llave,llave_activacion,codigo_producto ,fecha_activacion, fecha_dispositivo, fecha_fin_vigencia, tipo_dispositivo, plataforma_dispositivo, version_dispositivo)  VALUES($id_cliente, '$codigo_activacion', '$nombre_dispositivo','$id_dispositivo','$llave','$licencia_dispositivo','$codigo_producto','$fecha_dispositivo','$fecha_dispositivo','$fecha_fin_vigencia','$tipo_dispositivo','$plataforma_dispositivo','$version_dispositivo')";
        if (mysqli_query($conn, $sql)) 
        {
               $datos=array();
                 array_push($datos,array(
                 'error'=>"0",'mensaje'=>"Correcto"));
                  echo utf8_encode(json_encode($datos));
        }
        else {
            $datos=array();
                 array_push($datos,array(
                 'error'=>"3",'mensaje'=>mysqli_error($conn)));
                  echo utf8_encode(json_encode($datos));
        }
        mysqli_close($conn);
    }
    else
    {
         $datos=array();
         array_push($datos,array(
         'error'=>"2",'mensaje'=>"Error de comunicaci贸n con el servidor"));
         echo utf8_encode(json_encode($datos));
    }
?> 
