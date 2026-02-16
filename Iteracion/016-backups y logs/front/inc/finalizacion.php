<?php
/*
  Asegura que exista el carrito en sesión.
  El flujo de la app guarda las reservas temporales en $_SESSION["carrito"] antes de confirmarlas en BD.
*/
if (!isset($_SESSION["carrito"])) {
  $_SESSION["carrito"] = [];
}

/*
  Conexión a la base de datos.
  En esta pantalla se usa para:
  - Validar que las horas siguen libres (anti doble-reserva).
  - Insertar cliente, reserva y líneas de reserva si todo está OK.
*/
$conexion = new mysqli("localhost", "reserva_empresa", "Reservaempresa123_", "reserva_empresa");
$conexion->set_charset("utf8mb4");

/*
  Si alguien entra por URL (GET) sin haber enviado el formulario del carrito (POST),
  mostramos un mensaje simple. La reserva real solo se guarda cuando llega por POST.
*/
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
?>
  <section class="finalizacion">
    <h3>Reserva finalizada</h3>
    <p>Muchas gracias por tu reserva</p>
    <a class="btn btn--secondary" href="?">⬅ Volver al catálogo</a>
  </section>
<?php
  return;
}

/*
  Datos del cliente (vienen del formulario del carrito).
  Se recortan espacios para evitar datos con solo " " y normalizar.
*/
$nombre    = trim((string)($_POST["nombre"] ?? ""));
$apellidos = trim((string)($_POST["apellidos"] ?? ""));
$email     = trim((string)($_POST["email"] ?? ""));
$telefono  = trim((string)($_POST["telefono"] ?? ""));

/*
  Validación mínima: si falta algún campo, no se procesa.
  Esto evita insertar registros incompletos en la tabla cliente.
*/
if ($nombre === "" || $apellidos === "" || $email === "" || $telefono === "") {
?>
  <section class="finalizacion">
    <h3>Error</h3>
    <p>Faltan datos del cliente. Vuelve al carrito y completa el formulario.</p>
    <a class="btn btn--secondary" href="?operacion=carrito">⬅ Volver al carrito</a>
  </section>
<?php
  return;
}

/*
  No tiene sentido confirmar si el carrito está vacío.
  Esto evita insertar una reserva sin líneas.
*/
if (count($_SESSION["carrito"]) === 0) {
?>
  <section class="finalizacion">
    <h3>Carrito vacío</h3>
    <p>No hay reservas para confirmar.</p>
    <a class="btn btn--secondary" href="?">⬅ Volver al catálogo</a>
  </section>
<?php
  return;
}

/*
  Helpers de hora:
  - nextHour: calcula la hora siguiente (H:i) para validar duración 2.
  - horaEnRango: comprueba que la hora está dentro del horario permitido.
*/
function nextHour(string $hora): string {
  $dt = DateTime::createFromFormat("H:i", $hora);
  if (!$dt) return "";
  $dt->modify("+1 hour");
  return $dt->format("H:i");
}

function horaEnRango(string $hora): bool {
  return preg_match('/^\d{2}:\d{2}$/', $hora) === 1
      && $hora >= "09:00"
      && $hora <= "21:00";
}

try {
  /*
    Inicia una transacción para que todo sea consistente:
    - Si algo falla (validación o inserción), se hace rollback y no se guarda nada a medias.
  */
  $conexion->begin_transaction();

  /*
    Validación anti doble-reserva y reglas de duración=2.

    Por qué es necesaria:
    - En campo.php deshabilitas horas ocupadas para ayudar al usuario.
    - Pero entre que el usuario elige y confirma, otro usuario podría reservar la misma hora.
    - Por eso aquí se revalida contra la BD justo antes de insertar.

    Reglas:
    1) (campo_id + dia + hora) debe estar libre siempre.
    2) Si duracion=2:
       - no se puede empezar a las 21:00
       - (hora+1) también debe estar libre
  */

  $stmtCheck = $conexion->prepare(
    "SELECT id FROM lineareserva WHERE campo_id = ? AND dia = ? AND hora = ? LIMIT 1"
  );

  foreach ($_SESSION["carrito"] as $item) {
    $campoId  = (int)($item["campo_id"] ?? 0);
    $dia      = (string)($item["dia"] ?? "");
    $hora     = (string)($item["hora"] ?? "");
    $duracion = (int)($item["duracion"] ?? 1);

    /*
      Validación de estructura del item de sesión:
      - evita datos corruptos en el carrito o manipulaciones por POST/GET.
    */
    if ($campoId <= 0 || $dia === "" || $hora === "" || $duracion < 1 || $duracion > 2) {
      $conexion->rollback();
?>
      <section class="finalizacion">
        <h3>Error</h3>
        <p>Hay datos inválidos en el carrito.</p>
        <a class="btn btn--secondary" href="?operacion=carrito">⬅ Volver al carrito</a>
      </section>
<?php
      return;
    }

    /*
      Validación mínima de fecha: debe ser YYYY-MM-DD.
      Esto coincide con el formato que envía el input type="date".
    */
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) {
      $conexion->rollback();
?>
      <section class="finalizacion">
        <h3>Error</h3>
        <p>La fecha del carrito no es válida.</p>
        <a class="btn btn--secondary" href="?operacion=carrito">⬅ Volver al carrito</a>
      </section>
<?php
      return;
    }

    /*
      Validación de hora dentro del horario.
      El rango permitido se controla por servidor, aunque el front tenga select.
    */
    if (!horaEnRango($hora)) {
      $conexion->rollback();
?>
      <section class="finalizacion">
        <h3>Error</h3>
        <p>La hora seleccionada no es válida.</p>
        <a class="btn btn--secondary" href="?operacion=carrito">⬅ Volver al carrito</a>
      </section>
<?php
      return;
    }

    /*
      Regla: si son 2 horas, no puede empezar a las 21:00
      porque se saldría del horario.
    */
    if ($duracion === 2 && $hora === "21:00") {
      $conexion->rollback();
?>
      <section class="finalizacion">
        <h3>Duración no válida</h3>
        <p>No puedes reservar 2 horas empezando a las 21:00.</p>
        <a class="btn btn--secondary" href="?operacion=carrito">⬅ Volver al carrito</a>
      </section>
<?php
      return;
    }

    /*
      Chequeo de disponibilidad de la hora principal.
      Si existe un registro en lineareserva para ese campo/día/hora -> ya está ocupada.
    */
    $stmtCheck->bind_param("iss", $campoId, $dia, $hora);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();

    if ($resCheck && $resCheck->num_rows > 0) {
      $conexion->rollback();
?>
      <section class="finalizacion">
        <h3>Hora no disponible</h3>
        <p>Alguien reservó esa hora antes que tú. Vuelve al carrito y elige otra.</p>
        <a class="btn btn--secondary" href="?operacion=carrito">⬅ Volver al carrito</a>
      </section>
<?php
      return;
    }

    /*
      Si la duración es 2, se valida también la hora siguiente:
      - que exista (formato correcto)
      - que no se salga del horario
      - que esté libre en BD
    */
    if ($duracion === 2) {
      $hora2 = nextHour($hora);

      if ($hora2 === "" || $hora2 > "21:00") {
        $conexion->rollback();
?>
        <section class="finalizacion">
          <h3>Duración no válida</h3>
          <p>La reserva de 2 horas se sale del horario.</p>
          <a class="btn btn--secondary" href="?operacion=carrito">⬅ Volver al carrito</a>
        </section>
<?php
        return;
      }

      $stmtCheck->bind_param("iss", $campoId, $dia, $hora2);
      $stmtCheck->execute();
      $resCheck2 = $stmtCheck->get_result();

      if ($resCheck2 && $resCheck2->num_rows > 0) {
        $conexion->rollback();
?>
        <section class="finalizacion">
          <h3>Hora no disponible</h3>
          <p>No puedes reservar 2 horas porque la siguiente hora está ocupada.</p>
          <a class="btn btn--secondary" href="?operacion=carrito">⬅ Volver al carrito</a>
        </section>
<?php
        return;
      }
    }
  }

  /*
    Inserción en BD (flujo):
    1) Inserta cliente y obtiene su id.
    2) Inserta reserva (cabecera) ligada al cliente y obtiene id de reserva.
    3) Inserta cada item del carrito como lineareserva ligada a reserva y campo.
  */

  $stmtCliente = $conexion->prepare(
    "INSERT INTO cliente (nombre, apellidos, email, telefono) VALUES (?, ?, ?, ?)"
  );
  $stmtCliente->bind_param("ssss", $nombre, $apellidos, $email, $telefono);
  $stmtCliente->execute();
  $clienteId = $conexion->insert_id;

  $fechaReserva = date("Y-m-d H:i:s");
  $stmtReserva = $conexion->prepare(
    "INSERT INTO reserva (fecha, cliente_id) VALUES (?, ?)"
  );
  $stmtReserva->bind_param("si", $fechaReserva, $clienteId);
  $stmtReserva->execute();
  $reservaId = $conexion->insert_id;

  $stmtLinea = $conexion->prepare(
    "INSERT INTO lineareserva (reserva_id, campo_id, dia, hora, duracion) VALUES (?, ?, ?, ?, ?)"
  );

  foreach ($_SESSION["carrito"] as $item) {
    $campoId  = (int)$item["campo_id"];
    $dia      = (string)$item["dia"];
    $hora     = (string)$item["hora"];
    $duracion = (string)$item["duracion"];

    $stmtLinea->bind_param("iisss", $reservaId, $campoId, $dia, $hora, $duracion);
    $stmtLinea->execute();
  }

  /*
    Si todo fue bien:
    - commit confirma los cambios
    - se limpia el carrito para evitar duplicados por refresh
  */
  $conexion->commit();
  $_SESSION["carrito"] = [];
?>
  <section class="finalizacion">
    <h3>Reserva finalizada</h3>
    <p>Muchas gracias por tu reserva</p>
    <a class="btn btn--secondary" href="?">⬅ Volver al catálogo</a>
  </section>
<?php

} catch (Throwable $e) {
  /*
    Si ocurre cualquier error:
    - rollback deshace todo
    - se muestra un mensaje genérico al usuario
  */
  $conexion->rollback();
?>
  <section class="finalizacion">
    <h3>Error</h3>
    <p>No se pudo guardar la reserva. Inténtalo de nuevo.</p>
    <a class="btn btn--secondary" href="?operacion=carrito">⬅ Volver al carrito</a>
  </section>
<?php
}
?>
