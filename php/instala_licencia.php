<?php
    if(isset($_GET['id'])&& isset($_GET["codigoProducto"])&& isset($_GET["codigoActivacion"]))
   {
             $id_dispositivo = $_GET['id'];
             $codigoProducto = $_GET['codigoProducto'];
             $codigoActivacion = $_GET['codigoActivacion'];
             $cnx=new PDO("mysql:host=109.106.250.106;dbname=intelisy_licencias","intelisy_root","Lmenss130813**");
             $result=$cnx->query("SELECT dispositivos_activados.id_dispositivo_activado, dispositivos_activados.cliente,dispositivos_activados.n_activaciones, clientes.empresa, clientes.email, DATE_FORMAT(dispositivos_activados.fecha_activacion, '%Y-%m-%d') AS fecha_activacion, DATE_FORMAT(dispositivos_activados.fecha_fin_vigencia, '%Y-%m-%d') AS fecha_fin_vigencia, dispositivos_activados.eliminado, dispositivos_activados.lista_negra FROM dispositivos_activados, clientes WHERE clientes.id_cliente=dispositivos_activados.cliente AND dispositivos_activados.codigo_activacion='$codigoActivacion' AND dispositivos_activados.id_dispositivo='$id_dispositivo' AND dispositivos_activados.codigo_producto='$codigoProducto' ORDER BY dispositivos_activados.id_dispositivo_activado DESC LIMIT 1;");
             $row = $result->rowCount();
            if ($row > 0) 
            {  
                $date = date("Y-m-d H:i:s");
                //el dispositivo existe en BD y fue activado anteriormente
                  foreach ($result as $row)
                  {
                      $id_dispositivo_activado = $row["id_dispositivo_activado"];
                      $id_cliente= $row["cliente"];
                      $id_activacion=  $id_dispositivo_activado +  $id_cliente;
                      $nombre_cliente=  $row["empresa"];
                      $fecha_activacion= $row["fecha_activacion"];
                      $fecha_fin_vigencia= $row["fecha_fin_vigencia"];
                      $eliminado= $row["eliminado"];
                      $lista_negra= $row["lista_negra"];
                  }
                        $res=$cnx->query("SELECT SUM(dispositivos_activados.n_activaciones) AS n_activos, asignacion_licencias.n_licencias AS n_licencias FROM dispositivos_activados INNER JOIN asignacion_licencias ON dispositivos_activados.codigo_activacion = asignacion_licencias.codigo_activacion WHERE dispositivos_activados.codigo_activacion='$codigoActivacion';");
                        $nurow = $res->rowCount();
                         if ($nurow > 0) 
                         { 
                             foreach ($res as $nurow)
                             {
                                 $n_activos=$nurow["n_activos"];
                                 $n_licencias=$nurow["n_licencias"];
                                 if($n_activos == null || $n_licencias==null)
                                 {
                                     $n_activos=0;
                                      $n_licencias=0;
                                 }
                             }
                             if ($n_activos >= $n_licencias)
                             {
                                       //error devolucion numero total de activaciones
                                      $datos=array();
                                      array_push($datos,array(
                                      'error'=>"5",'mensaje'=>"activado el numero total de licencias"));
                                       echo utf8_encode(json_encode($datos));
                                       return;
                             }
                         }
                         else{
                              $datos=array();
                              array_push($datos,array(
                              'error'=>"3",'mensaje'=>"Codigo de activacion invalido"));
                                echo utf8_encode(json_encode($datos));
                                return;
                         }
                         if($lista_negra == 1)
                         {
                                     $datos=array();
                                      array_push($datos,array(
                                      'error'=>"6",'mensaje'=>"Lista Negra"));
                                       echo utf8_encode(json_encode($datos));
                                       return;
                         }
                         if($date>$fecha_fin_vigencia)
                         {
                             $datos=array();
                                      array_push($datos,array(
                                      'error'=>"7",'mensaje'=>"La licencia caduco"));
                                       echo utf8_encode(json_encode($datos));
                                       return;
                         }
                         $stmt=$cnx->prepare("UPDATE dispositivos_activados SET n_activaciones=n_activaciones+1, eliminado='0' WHERE id_dispositivo='$id_dispositivo' AND codigo_activacion='$codigoActivacion';");
                         $stmt->execute();
                                      $datos=array();
                                      array_push($datos,array(
                                      'nombre_cliente'=>$nombre_cliente,
                                      'fecha_fin_vigencia'=>$fecha_fin_vigencia,
                                      'error'=>"10",
                                      'mensaje'=>"Correcto"));
                                       echo utf8_encode(json_encode($datos));
            }
            else
            {
                //no encontro resultados la primer consulta es decir no existe el dispositivo en esta BD ni fue activado antes
                 $result=$cnx->query("SELECT asignacion_licencias.cliente, clientes.empresa, asignacion_licencias.tipo_licencia ,asignacion_licencias.n_licencias FROM asignacion_licencias, clientes WHERE asignacion_licencias.cliente = clientes.id_cliente AND asignacion_licencias.codigo_activacion='$codigoActivacion' AND asignacion_licencias.codigo_producto='$codigoProducto';");
                 $nrow = $result->rowCount();
                 if ($nrow > 0) 
                  { 
                      //encontro el codigo de activacion 
                       foreach ($result as $row)
                         {
                              $id_cliente =$row["cliente"];
                              $nombre_cliente =$row["empresa"];
                              $tipo_licencia=$row["tipo_licencia"];
                              $n_licencias=$row["n_licencias"];
                         }
                        $res=$cnx->query("SELECT COUNT(dispositivos_activados.id_dispositivo_activado), SUM(dispositivos_activados.n_activaciones) AS n_dispositivos FROM dispositivos_activados WHERE dispositivos_activados.codigo_activacion='$codigoActivacion' AND dispositivos_activados.codigo_producto='$codigoProducto' AND dispositivos_activados.eliminado='0';");
                        $nurow = $res->rowCount();
                         if ($nurow > 0) 
                         { 
                             foreach ($res as $row)
                             {
                                 $n_dispositivos=$row["n_dispositivos"];
                                 if($n_dispositivos == null)
                                 {
                                     $n_dispositivos=0;
                                 }
                             }
                               if ($n_dispositivos >= $n_licencias)
                                {
                                      //error devolucion numero total de activaciones
                                      $datos=array();
                                      array_push($datos,array(
                                      'error'=>"5",'mensaje'=>"activado el numero total de licencias"));
                                       echo utf8_encode(json_encode($datos));
                                }
                                
                                $datos=array();
                                      array_push($datos,array(
                                      'id_cliente'=>$id_cliente,
                                      'nombre_cliente'=>$nombre_cliente,
                                      'tipo_licencia'=>$tipo_licencia,
                                      'n_licencias'=>$n_licencias,
                                      'n_dispositivos'=>$n_dispositivos,
                                      'error'=>"0",
                                      'mensaje'=>"Correcto"));
                                       echo utf8_encode(json_encode($datos));
                         }
                         else
                         {
                              $datos=array();
                              array_push($datos,array(
                              'error'=>"3",'mensaje'=>"Codigo de activacion invalido"));
                                echo utf8_encode(json_encode($datos));
                         }
                  }
                  else
                  {
                      //el codigo de activacion es malo
                      $datos=array();
                      array_push($datos,array(
                      'error'=>"3",'mensaje'=>"Codigo de activacion invalido"));
                      echo utf8_encode(json_encode($datos));
                  }
            }
    }
    else
    {
         $datos=array();
         array_push($datos,array(
         'error'=>"1",'mensaje'=>"Error de comunicacion con el servidor"));
         echo utf8_encode(json_encode($datos));
    }
?>