<table>
<?php
  // Si no viene tabla en GET, ponemos una por defecto
  if (!isset($_GET["tabla"])) {
    $_GET["tabla"] = "campo";
  }

  // Conexión común (como el ejemplo)
    $conexion = new mysqli("localhost", "reserva_empresa", "Reservaempresa123_", "reserva_empresa");
    $conexion->set_charset("utf8mb4");

  // Cabecera de la tabla (como el ejemplo: sale del primer registro)
  $resultado = $conexion->query("SELECT * FROM ".$_GET['tabla']." LIMIT 1;");
  while($fila = $resultado->fetch_assoc()){
    echo '<tr>';
    foreach($fila as $clave=>$valor){
      echo "<th>".$clave."</th>";
    }
    echo '</tr>';
  }

  // Cuerpo de la tabla
  $resultado = $conexion->query("SELECT * FROM ".$_GET['tabla']);
  while($fila = $resultado->fetch_assoc()){
    echo '<tr>';
    foreach($fila as $clave=>$valor){
      echo "<td>".$valor."</td>";
    }
    echo '</tr>';
  }
?>
</table>
