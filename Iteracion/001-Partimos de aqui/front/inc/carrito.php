<?php
/*
  Asegura que exista el carrito en sesión.
  El carrito es un array de items temporales (campo_id, dia, hora, duracion) antes de guardarlos en BD.
*/
if (!isset($_SESSION["carrito"])) {
  $_SESSION["carrito"] = [];
}

/*
  Convierte el valor técnico del tipo (guardado en BD) a un texto legible para el usuario.
  Evita mostrar valores como "futbol_sala" o "futbol7a" en la UI.
*/
function tipoBonito($tipo){
  $t = strtolower(trim((string)$tipo));

  if ($t === "futbol_sala" || $t === "futbol sala") return "Fútbol sala";
  if ($t === "futbol7a" || $t === "futbol7" || $t === "futbol 7") return "Fútbol 7";

  $t = str_replace(["_", "-"], " ", $t);
  return mb_convert_case($t, MB_CASE_TITLE, "UTF-8");
}

/*
  Acción: vaciar el carrito.
  Se dispara por GET (?operacion=carrito&vaciar=1).
  Redirigimos para evitar re-ejecuciones al refrescar.
*/
if (isset($_GET["vaciar"])) {
  $_SESSION["carrito"] = [];
  header("Location: ?operacion=carrito");
  exit;
}

/*
  Acción: eliminar un item del carrito por su índice.
  Se dispara por GET (?operacion=carrito&del=INDEX).
*/
if (isset($_GET["del"])) {
  $idx = (int)$_GET["del"];

  if (isset($_SESSION["carrito"][$idx])) {
    array_splice($_SESSION["carrito"], $idx, 1);
  }

  header("Location: ?operacion=carrito");
  exit;
}

/*
  Acción: aumentar duración (+) de una reserva (máximo 2).
  Se dispara por GET (?operacion=carrito&inc=INDEX).
  Solo modifica la duración; la validación real de disponibilidad se hace al confirmar (finalizacion.php).
*/
if (isset($_GET["inc"])) {
  $idx = (int)$_GET["inc"];

  if (isset($_SESSION["carrito"][$idx])) {
    $dur = (int)$_SESSION["carrito"][$idx]["duracion"];
    if ($dur < 2) {
      $dur++;
      $_SESSION["carrito"][$idx]["duracion"] = (string)$dur;
    }
  }

  header("Location: ?operacion=carrito");
  exit;
}

/*
  Acción: añadir un item al carrito.
  Llega por POST desde campo.php, con los datos elegidos por el usuario:
  - campo (id)
  - dia (YYYY-MM-DD)
  - hora (H:i)
  - duracion (1 o 2)
  Se guarda en sesión y se redirige para mostrar el carrito.
*/
if (isset($_POST["campo"], $_POST["dia"], $_POST["hora"], $_POST["duracion"])) {

  $campo_id = (int)$_POST["campo"];
  $dia = (string)$_POST["dia"];
  $hora = (string)$_POST["hora"];
  $duracion = (string)$_POST["duracion"];

  $_SESSION["carrito"][] = [
    "campo_id" => $campo_id,
    "dia" => $dia,
    "hora" => $hora,
    "duracion" => $duracion
  ];

  header("Location: ?operacion=carrito");
  exit;
}

/*
  Conexión a BD para poder:
  - sacar el nombre, tipo y precio_hora de cada campo del carrito.
  El carrito en sesión solo guarda ids y selección de fecha/hora/duración.
*/
$conexion = new mysqli("localhost", "reserva_empresa", "Reservaempresa123_", "reserva_empresa");
$conexion->set_charset("utf8mb4");

/*
  Total acumulado de precios del carrito.
  Se calcula recorriendo los items:
  precio = precio_hora * duracion
*/
$total = 0;
?>

<section class="carrito">
  <div class="cart-layout">

    <div class="cart-left">
      <div class="table-wrap">
        <table>
        <thead>
          <tr>
            <th>Campo</th>
            <th>Tipo</th>
            <th>Fecha</th>
            <th>Hora</th>
            <th>Duración</th>
            <th>Precio</th>
            <th></th>
          </tr>
        </thead>

        <tbody>
          <?php if (count($_SESSION["carrito"]) == 0) { ?>
            <tr>
              <td colspan="7">Carrito vacío</td>
            </tr>
          <?php } else { ?>

            <?php foreach ($_SESSION["carrito"] as $i => $item) {

              $campo_id = (int)$item["campo_id"];
              $dia = $item["dia"];
              $hora = $item["hora"];
              $duracion = (int)$item["duracion"];

              /*
                Para cada item, consultamos los datos del campo en BD.
                Esto permite mostrar información actual (nombre/tipo/precio) a partir del id.
              */
              $res = $conexion->query("SELECT nombre, tipo, precio_hora FROM campo WHERE id = ".$campo_id);
              $campo = $res ? $res->fetch_assoc() : null;

              $nombre = $campo ? $campo["nombre"] : "Campo desconocido";
              $tipo = $campo ? $campo["tipo"] : "-";
              $precio_hora = $campo ? (float)$campo["precio_hora"] : 0;

              $precio = $precio_hora * $duracion;
              $total += $precio;
            ?>
              <tr>
                <td><?php echo htmlspecialchars($nombre); ?></td>
                <td><?php echo htmlspecialchars(tipoBonito($tipo)); ?></td>
                <td><?php echo htmlspecialchars($dia); ?></td>
                <td><?php echo htmlspecialchars($hora); ?></td>
                <td><?php echo $duracion; ?> hora(s)</td>
                <td><?php echo (int)$precio; ?>€</td>
                <td>
                  <?php if ($duracion < 2) { ?>
                    <a class="btn-mas" href="?operacion=carrito&inc=<?php echo $i; ?>">+</a>
                  <?php } else { ?>
                    <span class="btn-mas-disabled">+</span>
                  <?php } ?>
                  &nbsp;
                  <a class="btn-eliminar" href="?operacion=carrito&del=<?php echo $i; ?>">🗑 Eliminar</a>
                </td>
              </tr>
            <?php } ?>

            <tr>
              <td colspan="5">Total</td>
              <td><?php echo (int)$total; ?>€</td>
              <td>
                <a class="btn-vaciar" href="?operacion=carrito&vaciar=1">🧹 Vaciar</a>
              </td>
            </tr>

          <?php } ?>
        </tbody>
        </table>
      </div>

      <?php if (count($_SESSION["carrito"]) == 0) { ?>
        <div class="cart-actions">
          <a class="btn btn--secondary" href="?">⬅ Volver al catálogo</a>
        </div>
      <?php } ?>
    </div>

    <div class="cart-right">
      <div class="panel">
        <h3>Datos del cliente</h3>

        <!--
          Formulario del cliente:
          Se envía a finalizacion.php por POST.
          finalizacion.php validará:
          - datos del cliente
          - carrito no vacío
          - disponibilidad real en BD
          y luego insertará cliente/reserva/lineas.
        -->
        <form method="post" action="?operacion=finalizacion">

        <label for="nombre">Nombre</label>
        <input class="control" id="nombre" name="nombre" type="text" required>

        <label for="apellidos">Apellidos</label>
        <input class="control" id="apellidos" name="apellidos" type="text" required>

        <label for="email">Email</label>
        <input class="control" id="email" name="email" type="email" required>

        <label for="telefono">Teléfono</label>
        <input class="control" id="telefono" name="telefono" type="text" required>

        <button class="btn btn--full" type="submit" <?php echo (count($_SESSION["carrito"])==0) ? 'disabled' : ''; ?>>
          ✅ Confirmar reserva
        </button>

        </form>
      </div>
    </div>

  </div>
</section>
