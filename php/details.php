<?php  
  
   /* 
 
   * El siguiente código obtendrá detalles de un solo producto
 
   * Un producto se identifica por ID de producto (pid)
   */  
  
   //matriz para respuesta JSON
  
   $response = array();  
  
   // incluir clase db connect
  
   require_once __DIR__ . '/database_connect.php';  
  
   // conectándose 
  
   $db = new DB_CONNECT();  
  
   // comprobar los datos de la publicación 
  
   if (isset($_GET["usuario"]) && isset($_GET["password"]) {  
  
      $Usuario = $_GET['usuario'];  
       $Contra = $_GET['password'];
  
      // obtener un producto de la tabla de productos 
  
      $result = mysql_query("SELECT * FROM usuarios WHERE usuario = $Usuario AND password = $Contra");  
  
      if (!emptyempty($result)) {  
  
         // comprobar el resultado vacío
  
         if (mysql_num_rows($result) > 0) {  
  
            $result = mysql_fetch_array($result);  
  
            $User = array();  
  
            $User["id_usuario"] = $result["id_usuario"];  
  
            $User["nombres"] = $result["nombres"];  
  
            $User["apellidos"] = $result["apellidos"];   
  
            //éxito  
  
            $response["success"] = 1;  
  
            // user node nodo de usuario
  
            $response["User"] = array();  
  
            array_push($response["User"], $User);  
  
            // repitiendo la respuesta JSON
  
            echo json_encode($response);  
  
         } else {  
  
            // ningún producto encontrado
  
            $response["success"] = 0;  
  
            $response["message"] = "Contraseña o Usuario incorrecto";  
  
            // no echo ningún usuario JSON  
  
            echo json_encode($response);  
  
         }  
  
      } else {  
  
         //ningún producto encontrado
  
         $response["success"] = 0;  
  
         $response["message"] = "Contraseña o Usuario incorrecto";  
  
         // echo sin usuarios JSON
  
         echo json_encode($response);
  
      }  
  
   }  
  
?>  