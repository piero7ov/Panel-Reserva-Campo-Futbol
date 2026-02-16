<!-- CATÁLOGO -->
<section class="catalogo">
  <?php 
    /*
      Conexión a la base de datos.
      Aquí se usa para listar todos los campos disponibles en forma de tarjetas.
    */
    $conexion = new mysqli("localhost", "reserva_empresa", "Reservaempresa123_", "reserva_empresa");
    $conexion->set_charset("utf8mb4");

    /*
      Convierte el valor "tipo" guardado en BD a un texto legible para el usuario.
      Esto evita mostrar valores técnicos como "futbol_sala" o "futbol7a".
    */
    function tipoBonito($tipo){
      $t = strtolower(trim((string)$tipo));

      if ($t === "futbol_sala" || $t === "futbol sala") return "Fútbol sala";
      if ($t === "futbol7a" || $t === "futbol7" || $t === "futbol 7") return "Fútbol 7";

      $t = str_replace(["_", "-"], " ", $t);
      return mb_convert_case($t, MB_CASE_TITLE, "UTF-8");
    }

    /*
      Consulta de catálogo:
      Devuelve todos los registros de la tabla campo para renderizar una tarjeta por cada uno.
    */
    $resultado = $conexion->query("SELECT * FROM campo");

    /*
      Render de tarjetas:
      Cada <article> representa un campo (nombre, tipo, descripción, precio y botón de reservar).
      El enlace lleva al detalle pasando el id por GET (?operacion=campo&campo=ID).
    */
    while($fila = $resultado->fetch_assoc()){
  ?>
    <article>
      <div
        class="imagen"
        style="background:url(img/<?php echo htmlspecialchars($fila['imagen']); ?>);background-size:cover;background-position:center center;"
      ></div>

      <h3><?php echo htmlspecialchars($fila['nombre']); ?></h3>

      <p class="tipo"><?php echo htmlspecialchars(tipoBonito($fila['tipo'])); ?></p>

      <p><?php echo htmlspecialchars($fila['descripcion']); ?></p>
      <p class="precio"><?php echo htmlspecialchars($fila['precio_hora']); ?>€/hora</p>

      <div class="cta">
        <a class="btn" href="?operacion=campo&campo=<?php echo (int)$fila['id']; ?>">📌 Reservar</a>
      </div>
    </article>
  <?php } ?>
</section>
