<?php
      $cnx=new PDO("mysql:host=109.106.250.106;dbname=intelisy_marsteco_control_inventarios","intelisy_root","Lmenss130813**");
      $result=$cnx->query("SELECT * FROM inventarios WHERE eliminado='0'");
             $nrow = $result->rowCount();
            if ($nrow > 0) 
            {  
              $datos=array();
              foreach ($result as $row)
              {
                 array_push($datos, array(
                 'id_inventario'=>$row['id_inventario'],
                 'nombre_inventario'=>$row['nombre_inventario'],
                 'eliminado'=>$row['eliminado']));
              }
                   echo utf8_encode(json_encode($datos));
            }
            else
            {
                 $datos=array();
                 array_push($datos,array(
                 'error'=>"1",'mensaje'=>"Ningún Almacén encontrado, sin registros."));
                  echo utf8_encode(json_encode($datos));
            }
?>