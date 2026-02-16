<?php
/**
 * inc/tabla.php — Panel: Reservas (JOIN) + CRUD básico (Insertar / Editar) + Clientes (Alta)
 * ---------------------------------------------------------------------------------------
 * Modelo de datos (según tu esquema):
 *  - cliente(id, nombre, apellidos, email, telefono)
 *  - campo(id, nombre, tipo, descripcion, precio_hora, imagen)
 *  - reserva(id, fecha, cliente_id, estado?)   <-- vamos a trabajar con "estado" si existe
 *  - lineareserva(id, reserva_id, campo_id, dia, hora, duracion)
 *
 * Importante:
 * - Este include se ejecuta dentro del HTML ya impreso por index.php (dentro de <main>).
 * - Evitamos header("Location: ...") porque a veces ya hay salida HTML; usamos redirección por JavaScript.
 * - Mantiene tus filtros/orden/listados/alta/edición tal como lo tienes, y añade:
 *    1) Estado en reserva (pendiente/confirmada/cancelada) si la columna existe
 *    2) Acción "Confirmar" y "Cancelar" (cambia estado)
 *    3) Acción "Eliminar definitivamente" SOLO si está cancelada (borra lineareserva y luego reserva)
 */

/* ============================
   1) Helpers
   ============================ */

function e($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

function js_redirect(string $url): void {
  echo "<script>window.location.href = " . json_encode($url) . ";</script>";
  exit;
}

/** datetime-local (YYYY-MM-DDTHH:MM) -> (YYYY-MM-DD HH:MM:SS) */
function normalizarDatetimeLocal(string $s): string {
  $s = trim($s);
  if ($s === "") return "";
  $s = str_replace("T", " ", $s);
  if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $s)) $s .= ":00";
  return $s;
}

function to_int($v): int {
  return (int)($v ?? 0);
}

/** Badge simple para estado (sin depender de CSS extra) */
function estado_badge(string $estado): string {
  $estado = strtolower(trim($estado));
  $txt = $estado !== "" ? $estado : "pendiente";

  $bg = "rgba(250,204,21,.16)";  // amarillo
  $bd = "rgba(250,204,21,.35)";
  $fg = "#fff7cc";

  if ($txt === "confirmada" || $txt === "confirmado") {
    $bg = "rgba(34,197,94,.16)";
    $bd = "rgba(34,197,94,.35)";
    $fg = "#d1fae5";
    $txt = "confirmada";
  } elseif ($txt === "cancelada" || $txt === "cancelado") {
    $bg = "rgba(239,68,68,.16)";
    $bd = "rgba(239,68,68,.35)";
    $fg = "#fee2e2";
    $txt = "cancelada";
  } else {
    $txt = "pendiente";
  }

  return "<span style='display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;border:1px solid {$bd};background:{$bg};color:{$fg};font-size:12px;white-space:nowrap;'>
            <span style='width:8px;height:8px;border-radius:999px;background:{$bd};display:inline-block;'></span>
            " . e(mb_strtoupper(mb_substr($txt, 0, 1), "UTF-8") . mb_substr($txt, 1)) . "
          </span>";
}

/* ============================
   2) Tablas permitidas
   ============================ */
$TABLAS_PERMITIDAS = [
  "reserva"  => "Reservas",
  "campo"    => "Campos",
  "cliente"  => "Clientes",
];

$tabla = $_GET["tabla"] ?? "reserva";
if (!isset($TABLAS_PERMITIDAS[$tabla])) $tabla = "reserva";

$accion = $_GET["accion"] ?? "";

/* ============================
   3) Conexión (usa config del index.php)
   ============================ */
if (!isset($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME)) {
  $DB_HOST = "localhost";
  $DB_USER = "reserva_empresa";
  $DB_PASS = "Reservaempresa123_";
  $DB_NAME = "reserva_empresa";
}

$cn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$cn->set_charset("utf8mb4");

/* ============================
   4) Detectar si existe columna "estado" en reserva
   (para que el panel no reviente si aún no la has creado)
   ============================ */
$HAS_ESTADO = false;
if ($resCol = $cn->query("SHOW COLUMNS FROM reserva LIKE 'estado'")) {
  $HAS_ESTADO = ($resCol->num_rows > 0);
  $resCol->free();
}

/* ============================
   5) Datos auxiliares (selects)
   ============================ */

function getClientes(mysqli $cn): array {
  $out = [];
  $sql = "SELECT id, nombre, apellidos, email, telefono FROM cliente ORDER BY id DESC";
  if ($res = $cn->query($sql)) {
    while ($r = $res->fetch_assoc()) $out[] = $r;
  }
  return $out;
}

function getCampos(mysqli $cn): array {
  $out = [];
  $sql = "SELECT id, nombre, tipo, precio_hora FROM campo ORDER BY id DESC";
  if ($res = $cn->query($sql)) {
    while ($r = $res->fetch_assoc()) $out[] = $r;
  }
  return $out;
}

/* ============================
   6) RESERVAS — acciones de estado y eliminación definitiva
   ============================ */
/**
 * Acciones:
 * - confirmar_reserva: estado -> confirmada
 * - cancelar_reserva:  estado -> cancelada
 * - eliminar_reserva:  SOLO si estado es cancelada:
 *      borra lineareserva (por FK) y luego reserva
 *
 * Nota:
 * - Se ejecuta solo si estamos en tabla=reserva (para evitar “colisiones” con otros forms).
 * - Usamos return_url para volver a la misma vista (con filtros/orden).
 */
if ($tabla === "reserva" && isset($_POST["accion_reserva"])) {

  $accion_reserva = (string)($_POST["accion_reserva"] ?? "");
  $reserva_id = to_int($_POST["reserva_id"] ?? 0);
  $return_url = (string)($_POST["return_url"] ?? "?tabla=reserva");

  if ($reserva_id <= 0) {
    echo "<div style='margin:0 0 12px; padding:10px 12px; border-radius:12px; background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25);'>
            <strong>Error:</strong> Reserva inválida.
          </div>";
  } elseif (!$HAS_ESTADO && ($accion_reserva === "confirmar" || $accion_reserva === "cancelar" || $accion_reserva === "eliminar")) {
    echo "<div style='margin:0 0 12px; padding:10px 12px; border-radius:12px; background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25);'>
            <strong>Error:</strong> Falta la columna <code>estado</code> en la tabla <code>reserva</code>.
          </div>";
  } else {
    // Leemos estado actual (si aplica)
    $estado_actual = "pendiente";
    if ($HAS_ESTADO) {
      $stE = $cn->prepare("SELECT estado FROM reserva WHERE id = ? LIMIT 1");
      $stE->bind_param("i", $reserva_id);
      $stE->execute();
      $resE = $stE->get_result();
      $rowE = $resE ? $resE->fetch_assoc() : null;
      $stE->close();
      if ($rowE && isset($rowE["estado"]) && (string)$rowE["estado"] !== "") {
        $estado_actual = strtolower(trim((string)$rowE["estado"]));
      }
    }

    // Ejecutamos acción
    if ($accion_reserva === "confirmar") {

      // Confirmar (si no está cancelada)
      if ($estado_actual === "cancelada") {
        js_redirect($return_url . "&ok=estado_no_permitido");
      }

      try {
        $nuevo = "confirmada";
        $st = $cn->prepare("UPDATE reserva SET estado = ? WHERE id = ?");
        $st->bind_param("si", $nuevo, $reserva_id);
        $st->execute();
        $st->close();

        js_redirect($return_url . "&ok=estado_confirmada");
      } catch (Throwable $ex) {
        echo "<div style='margin:0 0 12px; padding:10px 12px; border-radius:12px; background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25);'>
                <strong>Error:</strong> no se pudo confirmar.
              </div>";
      }

    } elseif ($accion_reserva === "cancelar") {

      // Cancelar (siempre permite)
      try {
        $nuevo = "cancelada";
        $st = $cn->prepare("UPDATE reserva SET estado = ? WHERE id = ?");
        $st->bind_param("si", $nuevo, $reserva_id);
        $st->execute();
        $st->close();

        js_redirect($return_url . "&ok=estado_cancelada");
      } catch (Throwable $ex) {
        echo "<div style='margin:0 0 12px; padding:10px 12px; border-radius:12px; background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25);'>
                <strong>Error:</strong> no se pudo cancelar.
              </div>";
      }

    } elseif ($accion_reserva === "eliminar") {

      // Eliminar definitivo: SOLO si cancelada
      if ($estado_actual !== "cancelada") {
        js_redirect($return_url . "&ok=eliminar_solo_cancelada");
      }

      $cn->begin_transaction();
      try {
        // 1) Borrar líneas asociadas (por la FK)
        $st1 = $cn->prepare("DELETE FROM lineareserva WHERE reserva_id = ?");
        $st1->bind_param("i", $reserva_id);
        $st1->execute();
        $st1->close();

        // 2) Borrar reserva
        $st2 = $cn->prepare("DELETE FROM reserva WHERE id = ? AND estado = 'cancelada'");
        $st2->bind_param("i", $reserva_id);
        $st2->execute();
        $st2->close();

        $cn->commit();
        js_redirect($return_url . "&ok=eliminada");

      } catch (Throwable $ex) {
        $cn->rollback();
        echo "<div style='margin:0 0 12px; padding:10px 12px; border-radius:12px; background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25);'>
                <strong>Error:</strong> no se pudo eliminar definitivamente.
              </div>";
      }

    } else {
      echo "<div style='margin:0 0 12px; padding:10px 12px; border-radius:12px; background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25);'>
              <strong>Error:</strong> acción desconocida.
            </div>";
    }
  }
}

/* ============================
   7) CLIENTES — INSERTAR
   ============================ */
if ($tabla === "cliente" && isset($_POST["crear_cliente"])) {

  $nombre    = trim((string)($_POST["nombre"] ?? ""));
  $apellidos = trim((string)($_POST["apellidos"] ?? ""));
  $email     = trim((string)($_POST["email"] ?? ""));
  $telefono  = trim((string)($_POST["telefono"] ?? ""));

  $errores = [];
  if ($nombre === "") $errores[] = "Debes indicar el nombre.";
  if ($email === "" && $telefono === "") $errores[] = "Debes indicar email o teléfono (al menos uno).";

  if (count($errores) > 0) {
    echo "<div style='margin:0 0 12px; padding:10px 12px; border-radius:12px; background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25);'>";
    echo "<strong>Revisa:</strong><br>";
    foreach ($errores as $er) echo "• " . e($er) . "<br>";
    echo "</div>";
  } else {
    try {
      $st = $cn->prepare("INSERT INTO cliente (nombre, apellidos, email, telefono) VALUES (?, ?, ?, ?)");
      $st->bind_param("ssss", $nombre, $apellidos, $email, $telefono);
      $st->execute();
      $st->close();

      js_redirect("?tabla=cliente&ok=cliente_creado");
    } catch (Throwable $ex) {
      echo "<div style='margin:0 0 12px; padding:10px 12px; border-radius:12px; background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25);'>";
      echo "<strong>Error:</strong> no se pudo crear el cliente.";
      echo "</div>";
    }
  }
}

/* ============================
   8) RESERVAS — INSERTAR (reserva + 1 línea)
   + Permite crear cliente nuevo en el momento
   ============================ */
if ($tabla === "reserva" && isset($_POST["crear_reserva"])) {

  // Modo cliente
  $cliente_nuevo = isset($_POST["cliente_nuevo"]) && (string)$_POST["cliente_nuevo"] === "1";

  // Si es cliente existente
  $cliente_id = to_int($_POST["cliente_id"] ?? 0);

  // Si es cliente nuevo (campos)
  $nuevo_nombre    = trim((string)($_POST["nuevo_nombre"] ?? ""));
  $nuevo_apellidos = trim((string)($_POST["nuevo_apellidos"] ?? ""));
  $nuevo_email     = trim((string)($_POST["nuevo_email"] ?? ""));
  $nuevo_telefono  = trim((string)($_POST["nuevo_telefono"] ?? ""));

  // Datos reserva / línea
  $fecha    = normalizarDatetimeLocal((string)($_POST["fecha"] ?? ""));
  $campo_id = to_int($_POST["campo_id"] ?? 0);
  $dia      = trim((string)($_POST["dia"] ?? ""));
  $hora     = trim((string)($_POST["hora"] ?? ""));
  $duracion = trim((string)($_POST["duracion"] ?? ""));

  // Validación mínima
  $errores = [];

  if ($cliente_nuevo) {
    if ($nuevo_nombre === "") $errores[] = "En cliente nuevo: debes indicar el nombre.";
    if ($nuevo_email === "" && $nuevo_telefono === "") $errores[] = "En cliente nuevo: debes indicar email o teléfono (al menos uno).";
  } else {
    if ($cliente_id <= 0) $errores[] = "Debes seleccionar un cliente.";
  }

  if ($fecha === "")  $errores[] = "Debes indicar la fecha de la reserva.";
  if ($campo_id <= 0) $errores[] = "Debes seleccionar un campo.";
  if ($dia === "")    $errores[] = "Debes indicar el día.";
  if ($hora === "")   $errores[] = "Debes indicar la hora.";
  if ($duracion === "") $errores[] = "Debes indicar la duración.";

  if (count($errores) > 0) {
    echo "<div style='margin:0 0 12px; padding:10px 12px; border-radius:12px; background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25);'>";
    echo "<strong>Revisa:</strong><br>";
    foreach ($errores as $er) echo "• " . e($er) . "<br>";
    echo "</div>";
  } else {

    $cn->begin_transaction();

    try {
      // 1) Si el cliente es nuevo, lo creamos primero
      if ($cliente_nuevo) {
        $stC = $cn->prepare("INSERT INTO cliente (nombre, apellidos, email, telefono) VALUES (?, ?, ?, ?)");
        $stC->bind_param("ssss", $nuevo_nombre, $nuevo_apellidos, $nuevo_email, $nuevo_telefono);
        $stC->execute();
        $cliente_id = (int)$cn->insert_id; // usamos este ID para la reserva
        $stC->close();
      }

      // 2) Insert RESERVA
      //    Si existe columna estado, dejamos estado inicial "pendiente"
      if ($HAS_ESTADO) {
        $estado_inicial = "pendiente";
        $stR = $cn->prepare("INSERT INTO reserva (fecha, cliente_id, estado) VALUES (?, ?, ?)");
        $stR->bind_param("sis", $fecha, $cliente_id, $estado_inicial);
      } else {
        $stR = $cn->prepare("INSERT INTO reserva (fecha, cliente_id) VALUES (?, ?)");
        $stR->bind_param("si", $fecha, $cliente_id);
      }

      $stR->execute();
      $reserva_id = (int)$cn->insert_id;
      $stR->close();

      // 3) Insert LINEARESERVA (1 línea)
      $stL = $cn->prepare("INSERT INTO lineareserva (reserva_id, campo_id, dia, hora, duracion) VALUES (?, ?, ?, ?, ?)");
      $stL->bind_param("iisss", $reserva_id, $campo_id, $dia, $hora, $duracion);
      $stL->execute();
      $stL->close();

      $cn->commit();
      js_redirect("?tabla=reserva&ok=creado");
    } catch (Throwable $ex) {
      $cn->rollback();
      echo "<div style='margin:0 0 12px; padding:10px 12px; border-radius:12px; background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25);'>";
      echo "<strong>Error:</strong> no se pudo crear la reserva.";
      echo "</div>";
    }
  }
}

/* ============================
   9) RESERVAS — EDITAR / ACTUALIZAR (reserva + línea)
   ============================ */
if ($tabla === "reserva" && isset($_POST["guardar_edicion"])) {

  $linea_id   = to_int($_POST["linea_id"] ?? 0);
  $reserva_id = to_int($_POST["reserva_id"] ?? 0);

  $cliente_id = to_int($_POST["cliente_id"] ?? 0);
  $fecha      = normalizarDatetimeLocal((string)($_POST["fecha"] ?? ""));

  $campo_id   = to_int($_POST["campo_id"] ?? 0);
  $dia        = trim((string)($_POST["dia"] ?? ""));
  $hora       = trim((string)($_POST["hora"] ?? ""));
  $duracion   = trim((string)($_POST["duracion"] ?? ""));

  $errores = [];
  if ($linea_id <= 0)   $errores[] = "Línea inválida.";
  if ($reserva_id <= 0) $errores[] = "Reserva inválida.";
  if ($cliente_id <= 0) $errores[] = "Cliente inválido.";
  if ($fecha === "")    $errores[] = "Fecha inválida.";
  if ($campo_id <= 0)   $errores[] = "Campo inválido.";
  if ($dia === "")      $errores[] = "Día inválido.";
  if ($hora === "")     $errores[] = "Hora inválida.";
  if ($duracion === "") $errores[] = "Duración inválida.";

  if (count($errores) > 0) {
    echo "<div style='margin:0 0 12px; padding:10px 12px; border-radius:12px; background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25);'>";
    echo "<strong>Revisa:</strong><br>";
    foreach ($errores as $er) echo "• " . e($er) . "<br>";
    echo "</div>";
  } else {
    $cn->begin_transaction();
    try {
      // Nota: aquí NO tocamos el estado; solo fecha/cliente.
      $stR = $cn->prepare("UPDATE reserva SET fecha = ?, cliente_id = ? WHERE id = ?");
      $stR->bind_param("sii", $fecha, $cliente_id, $reserva_id);
      $stR->execute();
      $stR->close();

      $stL = $cn->prepare("UPDATE lineareserva SET campo_id = ?, dia = ?, hora = ?, duracion = ? WHERE id = ? AND reserva_id = ?");
      $stL->bind_param("isssii", $campo_id, $dia, $hora, $duracion, $linea_id, $reserva_id);
      $stL->execute();
      $stL->close();

      $cn->commit();
      js_redirect("?tabla=reserva&ok=editado");
    } catch (Throwable $ex) {
      $cn->rollback();
      echo "<div style='margin:0 0 12px; padding:10px 12px; border-radius:12px; background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25);'>";
      echo "<strong>Error:</strong> no se pudo actualizar.";
      echo "</div>";
    }
  }
}

/* ============================
   10) Título
   ============================ */
echo "<h2 style='margin:0 0 14px; font-size:18px; letter-spacing:.2px;'>" . e($TABLAS_PERMITIDAS[$tabla]) . "</h2>";

/* Mensajes OK */
if (isset($_GET["ok"])) {
  $ok = (string)$_GET["ok"];
  $msg = "";

  if ($ok === "creado")         $msg = "Reserva creada correctamente.";
  if ($ok === "editado")        $msg = "Reserva actualizada correctamente.";
  if ($ok === "cliente_creado") $msg = "Cliente creado correctamente.";

  if ($ok === "estado_confirmada") $msg = "Reserva confirmada.";
  if ($ok === "estado_cancelada")  $msg = "Reserva cancelada.";
  if ($ok === "eliminada")         $msg = "Reserva eliminada definitivamente.";

  if ($ok === "estado_no_permitido")     $msg = "No puedes confirmar una reserva cancelada.";
  if ($ok === "eliminar_solo_cancelada") $msg = "Solo puedes eliminar definitivamente reservas canceladas.";

  if ($msg !== "") {
    echo "<div style='margin:0 0 12px; padding:10px 12px; border-radius:12px; background:rgba(34,197,94,.12); border:1px solid rgba(34,197,94,.25);'>";
    echo e($msg);
    echo "</div>";
  }
}

/* ============================
   11) CLIENTES — NUEVO (form)
   ============================ */
if ($tabla === "cliente" && $accion === "nuevo") {

  echo "<div style='margin:0 0 12px; display:flex; gap:10px; align-items:center;'>";
  echo "<a class='btn' href='?tabla=cliente'>← Volver</a>";
  echo "</div>";

  echo "<form method='POST' action='?tabla=cliente&accion=nuevo' class='filters'>";
  echo "<input type='hidden' name='crear_cliente' value='1'>";

  echo "<div class='field grow'>";
  echo "<label>Nombre</label>";
  echo "<input type='text' name='nombre' placeholder='Nombre' required>";
  echo "</div>";

  echo "<div class='field grow'>";
  echo "<label>Apellidos</label>";
  echo "<input type='text' name='apellidos' placeholder='Apellidos'>";
  echo "</div>";

  echo "<div class='field grow'>";
  echo "<label>Email</label>";
  echo "<input type='text' name='email' placeholder='Email (opcional si hay teléfono)'>";
  echo "</div>";

  echo "<div class='field'>";
  echo "<label>Teléfono</label>";
  echo "<input type='text' name='telefono' placeholder='Teléfono (opcional si hay email)'>";
  echo "</div>";

  echo "<div class='actions'>";
  echo "<button class='btn primary' type='submit'>Crear</button>";
  echo "<a class='btn' href='?tabla=cliente'>Cancelar</a>";
  echo "</div>";

  echo "</form>";
  return;
}

/* ============================
   12) RESERVAS — NUEVA (form)
   ============================ */
if ($tabla === "reserva" && $accion === "nueva") {

  $clientes = getClientes($cn);
  $campos   = getCampos($cn);

  $fecha_default = date("Y-m-d\\TH:i");
  $dia_default   = date("Y-m-d");

  echo "<div style='margin:0 0 12px; display:flex; gap:10px; align-items:center;'>";
  echo "<a class='btn' href='?tabla=reserva'>← Volver</a>";
  echo "</div>";

  echo "<form method='POST' action='?tabla=reserva&accion=nueva' class='filters' style='margin-top:6px;'>";
  echo "<input type='hidden' name='crear_reserva' value='1'>";

  /* Cliente existente */
  echo "<div class='field grow'>";
  echo "<label>Cliente (existente)</label>";
  echo "<select name='cliente_id'>";
  echo "<option value='0'>Selecciona un cliente</option>";
  foreach ($clientes as $c) {
    $label = trim($c["nombre"] . " " . $c["apellidos"]);
    $extra = trim((string)$c["email"]);
    $txt = "#" . $c["id"] . " - " . $label;
    if ($extra !== "") $txt .= " (" . $extra . ")";
    echo "<option value='" . e($c["id"]) . "'>" . e($txt) . "</option>";
  }
  echo "</select>";
  echo "</div>";

  /* Toggle cliente nuevo */
  echo "<div class='field'>";
  echo "<label>Cliente nuevo</label>";
  echo "<div style='display:flex; gap:8px; align-items:center; padding:10px 12px; border-radius:12px; border:1px solid rgba(255,255,255,.14); background:rgba(255,255,255,.06);'>";
  echo "<input type='checkbox' id='cliente_nuevo' name='cliente_nuevo' value='1'>";
  echo "<label for='cliente_nuevo' style='margin:0; font-size:14px; opacity:.95;'>Crear cliente nuevo</label>";
  echo "</div>";
  echo "</div>";

  /* Datos cliente nuevo */
  echo "<div class='field grow'>";
  echo "<label>Nombre (cliente nuevo)</label>";
  echo "<input type='text' name='nuevo_nombre' placeholder='Nombre'>";
  echo "</div>";

  echo "<div class='field grow'>";
  echo "<label>Apellidos (cliente nuevo)</label>";
  echo "<input type='text' name='nuevo_apellidos' placeholder='Apellidos'>";
  echo "</div>";

  echo "<div class='field grow'>";
  echo "<label>Email (cliente nuevo)</label>";
  echo "<input type='text' name='nuevo_email' placeholder='Email (opcional si hay teléfono)'>";
  echo "</div>";

  echo "<div class='field'>";
  echo "<label>Teléfono (cliente nuevo)</label>";
  echo "<input type='text' name='nuevo_telefono' placeholder='Teléfono (opcional si hay email)'>";
  echo "</div>";

  /* Fecha reserva */
  echo "<div class='field'>";
  echo "<label>Fecha (reserva)</label>";
  echo "<input type='datetime-local' name='fecha' value='" . e($fecha_default) . "' required>";
  echo "</div>";

  /* Campo */
  echo "<div class='field grow'>";
  echo "<label>Campo</label>";
  echo "<select name='campo_id' required>";
  echo "<option value='0'>Selecciona un campo</option>";
  foreach ($campos as $ca) {
    $txt = "#" . $ca["id"] . " - " . $ca["nombre"] . " · " . $ca["tipo"] . " · " . $ca["precio_hora"];
    echo "<option value='" . e($ca["id"]) . "'>" . e($txt) . "</option>";
  }
  echo "</select>";
  echo "</div>";

  /* Día / Hora / Duración */
  echo "<div class='field'>";
  echo "<label>Día</label>";
  echo "<input type='date' name='dia' value='" . e($dia_default) . "' required>";
  echo "</div>";

  echo "<div class='field'>";
  echo "<label>Hora</label>";
  echo "<input type='time' name='hora' value='15:00' required>";
  echo "</div>";

  echo "<div class='field'>";
  echo "<label>Duración</label>";
  echo "<input type='text' name='duracion' value='1' placeholder='Ej: 1 / 2' required>";
  echo "</div>";

  echo "<div class='actions'>";
  echo "<button class='btn primary' type='submit'>Crear</button>";
  echo "<a class='btn' href='?tabla=reserva'>Cancelar</a>";
  echo "</div>";

  echo "</form>";
  return;
}

/* ============================
   13) RESERVAS — VER (detalle)
   ============================ */
if ($tabla === "reserva" && $accion === "ver") {
  $linea_id = to_int($_GET["linea_id"] ?? 0);

  $selectEstado = $HAS_ESTADO ? "r.estado AS estado," : "'pendiente' AS estado,";

  $sql = "
    SELECT
      r.id        AS reserva_id,
      r.fecha     AS fecha,
      {$selectEstado}
      c.id        AS cliente_id,
      c.nombre    AS cliente_nombre,
      c.apellidos AS cliente_apellidos,
      c.email     AS cliente_email,
      c.telefono  AS cliente_telefono,

      lr.id       AS linea_id,
      lr.dia      AS dia,
      lr.hora     AS hora,
      lr.duracion AS duracion,

      ca.id       AS campo_id,
      ca.nombre   AS campo_nombre,
      ca.tipo     AS campo_tipo,
      ca.precio_hora AS campo_precio_hora
    FROM lineareserva lr
    LEFT JOIN reserva r ON r.id = lr.reserva_id
    LEFT JOIN cliente c ON c.id = r.cliente_id
    LEFT JOIN campo ca  ON ca.id = lr.campo_id
    WHERE lr.id = ?
    LIMIT 1
  ";

  $st = $cn->prepare($sql);
  $st->bind_param("i", $linea_id);
  $st->execute();
  $res = $st->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $st->close();

  echo "<div style='margin:0 0 12px; display:flex; gap:10px; align-items:center;'>";
  echo "<a class='btn' href='?tabla=reserva'>← Volver</a>";
  if ($row) echo "<a class='btn primary' href='?tabla=reserva&accion=editar&linea_id=" . e($linea_id) . "'>Editar</a>";
  echo "</div>";

  if (!$row) {
    echo "<div style='padding:12px; border-radius:12px; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12);'>No encontrado.</div>";
    return;
  }

  $cliente = trim((string)$row["cliente_nombre"] . " " . (string)$row["cliente_apellidos"]);
  $estado = strtolower(trim((string)($row["estado"] ?? "pendiente")));
  if ($estado === "") $estado = "pendiente";

  echo "<table>";
  echo "<tr><th>Estado</th><td>" . estado_badge($estado) . "</td></tr>";
  echo "<tr><th>Campo</th><td>" . e($row["campo_nombre"]) . " (" . e($row["campo_tipo"]) . ")</td></tr>";
  echo "<tr><th>Precio/h</th><td>" . e($row["campo_precio_hora"]) . "</td></tr>";
  echo "<tr><th>Reserva</th><td>" . e($row["reserva_id"]) . "</td></tr>";
  echo "<tr><th>Fecha</th><td>" . e($row["fecha"]) . "</td></tr>";
  echo "<tr><th>Cliente</th><td>" . e($cliente) . "</td></tr>";
  echo "<tr><th>Email</th><td>" . e($row["cliente_email"]) . "</td></tr>";
  echo "<tr><th>Teléfono</th><td>" . e($row["cliente_telefono"]) . "</td></tr>";
  echo "<tr><th>Línea</th><td>" . e($row["linea_id"]) . "</td></tr>";
  echo "<tr><th>Día</th><td>" . e($row["dia"]) . "</td></tr>";
  echo "<tr><th>Hora</th><td>" . e($row["hora"]) . "</td></tr>";
  echo "<tr><th>Duración</th><td>" . e($row["duracion"]) . "</td></tr>";
  echo "</table>";
  return;
}

/* ============================
   14) RESERVAS — EDITAR (form)
   ============================ */
if ($tabla === "reserva" && $accion === "editar") {
  $linea_id = to_int($_GET["linea_id"] ?? 0);

  $selectEstado = $HAS_ESTADO ? "r.estado AS estado," : "'pendiente' AS estado,";

  $sql = "
    SELECT
      r.id        AS reserva_id,
      r.fecha     AS fecha,
      {$selectEstado}
      r.cliente_id AS cliente_id,

      lr.id       AS linea_id,
      lr.dia      AS dia,
      lr.hora     AS hora,
      lr.duracion AS duracion,
      lr.campo_id AS campo_id
    FROM lineareserva lr
    LEFT JOIN reserva r ON r.id = lr.reserva_id
    WHERE lr.id = ?
    LIMIT 1
  ";
  $st = $cn->prepare($sql);
  $st->bind_param("i", $linea_id);
  $st->execute();
  $res = $st->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $st->close();

  echo "<div style='margin:0 0 12px; display:flex; gap:10px; align-items:center;'>";
  echo "<a class='btn' href='?tabla=reserva'>← Volver</a>";
  echo "</div>";

  if (!$row) {
    echo "<div style='padding:12px; border-radius:12px; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12);'>No encontrado.</div>";
    return;
  }

  $clientes = getClientes($cn);
  $campos   = getCampos($cn);

  $fecha_val = (string)$row["fecha"];
  $fecha_val = str_replace(" ", "T", $fecha_val);
  $fecha_val = preg_replace('/:\d{2}$/', '', $fecha_val);

  $estado = strtolower(trim((string)($row["estado"] ?? "pendiente")));
  if ($estado === "") $estado = "pendiente";

  // Aviso de estado arriba (solo informativo)
  echo "<div style='margin:0 0 12px; padding:10px 12px; border-radius:12px; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12); display:flex; align-items:center; gap:10px;'>
          <strong>Estado:</strong> " . estado_badge($estado) . "
        </div>";

  echo "<form method='POST' action='?tabla=reserva&accion=editar&linea_id=" . e($linea_id) . "' class='filters'>";

  echo "<input type='hidden' name='guardar_edicion' value='1'>";
  echo "<input type='hidden' name='linea_id' value='" . e($row["linea_id"]) . "'>";
  echo "<input type='hidden' name='reserva_id' value='" . e($row["reserva_id"]) . "'>";

  echo "<div class='field grow'>";
  echo "<label>Cliente</label>";
  echo "<select name='cliente_id' required>";
  foreach ($clientes as $c) {
    $selected = ((int)$c["id"] === (int)$row["cliente_id"]) ? " selected" : "";
    $label = trim($c["nombre"] . " " . $c["apellidos"]);
    $extra = trim((string)$c["email"]);
    $txt = "#" . $c["id"] . " - " . $label;
    if ($extra !== "") $txt .= " (" . $extra . ")";
    echo "<option value='" . e($c["id"]) . "'" . $selected . ">" . e($txt) . "</option>";
  }
  echo "</select>";
  echo "</div>";

  echo "<div class='field'>";
  echo "<label>Fecha (reserva)</label>";
  echo "<input type='datetime-local' name='fecha' value='" . e($fecha_val) . "' required>";
  echo "</div>";

  echo "<div class='field grow'>";
  echo "<label>Campo</label>";
  echo "<select name='campo_id' required>";
  foreach ($campos as $ca) {
    $selected = ((int)$ca["id"] === (int)$row["campo_id"]) ? " selected" : "";
    $txt = "#" . $ca["id"] . " - " . $ca["nombre"] . " · " . $ca["tipo"] . " · " . $ca["precio_hora"];
    echo "<option value='" . e($ca["id"]) . "'" . $selected . ">" . e($txt) . "</option>";
  }
  echo "</select>";
  echo "</div>";

  echo "<div class='field'>";
  echo "<label>Día</label>";
  echo "<input type='date' name='dia' value='" . e($row["dia"]) . "' required>";
  echo "</div>";

  echo "<div class='field'>";
  echo "<label>Hora</label>";
  echo "<input type='time' name='hora' value='" . e($row["hora"]) . "' required>";
  echo "</div>";

  echo "<div class='field'>";
  echo "<label>Duración</label>";
  echo "<input type='text' name='duracion' value='" . e($row["duracion"]) . "' required>";
  echo "</div>";

  echo "<div class='actions'>";
  echo "<button class='btn primary' type='submit'>Guardar</button>";
  echo "<a class='btn' href='?tabla=reserva'>Cancelar</a>";
  echo "</div>";

  echo "</form>";
  return;
}

/* ============================
   15) CLIENTES — listado + filtro + orden + botón nuevo
   ============================ */
if ($tabla === "cliente") {

  $q = trim((string)($_GET["q"] ?? ""));
  $sort = (string)($_GET["sort"] ?? "id");
  $dir  = strtolower((string)($_GET["dir"] ?? "desc")) === "asc" ? "asc" : "desc";

  $SORT_MAP = [
    "id"        => "c.id",
    "nombre"    => "c.nombre",
    "apellidos" => "c.apellidos",
    "email"     => "c.email",
    "telefono"  => "c.telefono",
  ];
  if (!isset($SORT_MAP[$sort])) $sort = "id";

  echo "<div style='margin:0 0 10px; display:flex; gap:10px; align-items:center;'>";
  echo "<a class='btn primary' href='?tabla=cliente&accion=nuevo'>+ Nuevo cliente</a>";
  echo "</div>";

  $where = [];
  $types = "";
  $params = [];

  if ($q !== "") {
    $like = "%" . $q . "%";
    $where[] = "(c.nombre LIKE ? OR c.apellidos LIKE ? OR c.email LIKE ? OR c.telefono LIKE ?)";
    $types .= "ssss";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
  }

  $sql = "SELECT c.id, c.nombre, c.apellidos, c.email, c.telefono FROM cliente c";
  if (count($where) > 0) $sql .= " WHERE " . implode(" AND ", $where);
  $sql .= " ORDER BY " . $SORT_MAP[$sort] . " " . $dir;

  echo "<form class='filters' method='GET' action='?'>";
  echo "<input type='hidden' name='tabla' value='cliente'>";

  echo "<div class='field grow'>";
  echo "<label>Buscar cliente</label>";
  echo "<input type='text' name='q' value='" . e($q) . "' placeholder='Nombre, email o teléfono'>";
  echo "</div>";

  echo "<div class='actions'>";
  echo "<button class='btn primary' type='submit'>Aplicar</button>";
  echo "<a class='btn' href='?tabla=cliente'>Limpiar</a>";
  echo "</div>";

  echo "</form>";

  $st = $cn->prepare($sql);
  if ($types !== "") $st->bind_param($types, ...$params);
  $st->execute();
  $res = $st->get_result();

  $base = ["tabla" => "cliente", "q" => $q];
  $makeSortUrl = function(string $col) use ($base, $sort, $dir): string {
    $nextDir = ($sort === $col && $dir === "asc") ? "desc" : "asc";
    $qs = $base;
    $qs["sort"] = $col;
    $qs["dir"]  = $nextDir;
    return "?" . http_build_query($qs);
  };
  $arrow = function(string $col) use ($sort, $dir): string {
    if ($sort !== $col) return "";
    return $dir === "asc" ? " ▲" : " ▼";
  };

  echo "<table>";
  echo "<tr>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("id")) . "'>ID" . e($arrow("id")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("nombre")) . "'>Nombre" . e($arrow("nombre")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("apellidos")) . "'>Apellidos" . e($arrow("apellidos")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("email")) . "'>Email" . e($arrow("email")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("telefono")) . "'>Teléfono" . e($arrow("telefono")) . "</a></th>";
  echo "</tr>";

  if ($res && $res->num_rows > 0) {
    while ($r = $res->fetch_assoc()) {
      echo "<tr>";
      echo "<td>" . e($r["id"]) . "</td>";
      echo "<td>" . e($r["nombre"]) . "</td>";
      echo "<td>" . e($r["apellidos"]) . "</td>";
      echo "<td>" . e($r["email"]) . "</td>";
      echo "<td>" . e($r["telefono"]) . "</td>";
      echo "</tr>";
    }
  } else {
    echo "<tr><td colspan='5'>No hay registros.</td></tr>";
  }

  echo "</table>";
  $st->close();
  return;
}

/* ============================
   16) RESERVAS — listado + filtros + orden + acciones
   ============================ */
if ($tabla === "reserva") {

  $q = trim((string)($_GET["q"] ?? ""));
  $f_fecha = trim((string)($_GET["fecha"] ?? ""));
  $f_campo = to_int($_GET["campo_id"] ?? 0);

  $sort = (string)($_GET["sort"] ?? "reserva");
  $dir  = strtolower((string)($_GET["dir"] ?? "desc")) === "asc" ? "asc" : "desc";

  $SORT_MAP = [
    "reserva"   => "r.id",
    "fecha"     => "r.fecha",
    "cliente"   => "c.nombre",
    "email"     => "c.email",
    "telefono"  => "c.telefono",
    "dia"       => "lr.dia",
    "hora"      => "lr.hora",
    "duracion"  => "lr.duracion",
    "campo"     => "ca.nombre",
    "tipo"      => "ca.tipo",
    "precio"    => "ca.precio_hora",
    // Si en el futuro quieres ordenar por estado, se puede añadir aquí.
  ];
  if (!isset($SORT_MAP[$sort])) $sort = "reserva";

  $campos = getCampos($cn);

  echo "<div style='margin:0 0 10px; display:flex; gap:10px; align-items:center;'>";
  echo "<a class='btn primary' href='?tabla=reserva&accion=nueva'>+ Nueva reserva</a>";
  echo "</div>";

  echo "<form class='filters' method='GET' action='?'>";
  echo "<input type='hidden' name='tabla' value='reserva'>";

  echo "<div class='field grow'>";
  echo "<label>Buscar cliente</label>";
  echo "<input type='text' name='q' value='" . e($q) . "' placeholder='Nombre, email o teléfono'>";
  echo "</div>";

  echo "<div class='field'>";
  echo "<label>Fecha</label>";
  echo "<input type='date' name='fecha' value='" . e($f_fecha) . "'>";
  echo "</div>";

  echo "<div class='field grow'>";
  echo "<label>Campo</label>";
  echo "<select name='campo_id'>";
  echo "<option value='0'>Todos</option>";
  foreach ($campos as $ca) {
    $sel = ((int)$ca["id"] === $f_campo) ? " selected" : "";
    $txt = $ca["nombre"] . " · " . $ca["tipo"];
    echo "<option value='" . e($ca["id"]) . "'" . $sel . ">" . e($txt) . "</option>";
  }
  echo "</select>";
  echo "</div>";

  echo "<div class='actions'>";
  echo "<button class='btn primary' type='submit'>Aplicar</button>";
  echo "<a class='btn' href='?tabla=reserva'>Limpiar</a>";
  echo "</div>";

  echo "</form>";

  $where = [];
  $types = "";
  $params = [];

  if ($q !== "") {
    $like = "%" . $q . "%";
    $where[] = "(c.nombre LIKE ? OR c.apellidos LIKE ? OR c.email LIKE ? OR c.telefono LIKE ?)";
    $types .= "ssss";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
  }

  if ($f_fecha !== "") {
    $where[] = "(r.fecha LIKE ? OR lr.dia = ?)";
    $types .= "ss";
    $params[] = $f_fecha . "%";
    $params[] = $f_fecha;
  }

  if ($f_campo > 0) {
    $where[] = "(ca.id = ?)";
    $types .= "i";
    $params[] = $f_campo;
  }

  $selectEstado = $HAS_ESTADO ? "r.estado AS estado," : "'pendiente' AS estado,";

  $sql = "
    SELECT
      r.id        AS reserva_id,
      r.fecha     AS fecha,
      {$selectEstado}
      c.nombre    AS cliente_nombre,
      c.apellidos AS cliente_apellidos,
      c.email     AS cliente_email,
      c.telefono  AS cliente_telefono,

      lr.id       AS linea_id,
      lr.dia      AS dia,
      lr.hora     AS hora,
      lr.duracion AS duracion,

      ca.nombre   AS campo_nombre,
      ca.tipo     AS campo_tipo,
      ca.precio_hora AS campo_precio_hora
    FROM reserva r
    LEFT JOIN cliente c ON c.id = r.cliente_id
    LEFT JOIN lineareserva lr ON lr.reserva_id = r.id
    LEFT JOIN campo ca ON ca.id = lr.campo_id
  ";

  if (count($where) > 0) $sql .= " WHERE " . implode(" AND ", $where);

  $sql .= " ORDER BY " . $SORT_MAP[$sort] . " " . $dir . ", r.id DESC, lr.id ASC";

  $st = $cn->prepare($sql);
  if ($types !== "") $st->bind_param($types, ...$params);
  $st->execute();
  $res = $st->get_result();

  $base = ["tabla" => "reserva", "q" => $q, "fecha" => $f_fecha, "campo_id" => $f_campo];
  $makeSortUrl = function(string $col) use ($base, $sort, $dir): string {
    $nextDir = ($sort === $col && $dir === "asc") ? "desc" : "asc";
    $qs = $base;
    $qs["sort"] = $col;
    $qs["dir"]  = $nextDir;
    return "?" . http_build_query($qs);
  };
  $arrow = function(string $col) use ($sort, $dir): string {
    if ($sort !== $col) return "";
    return $dir === "asc" ? " ▲" : " ▼";
  };

  // URL de retorno para acciones POST (mantiene filtros/orden)
  $return_url = "?" . http_build_query(array_merge($base, ["sort" => $sort, "dir" => $dir]));

  echo "<table>";
  echo "<tr>";

  echo "<th><a class='sort' href='" . e($makeSortUrl("reserva")) . "'>Reserva" . e($arrow("reserva")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("fecha")) . "'>Fecha" . e($arrow("fecha")) . "</a></th>";
  echo "<th>Estado</th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("cliente")) . "'>Cliente" . e($arrow("cliente")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("email")) . "'>Email" . e($arrow("email")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("telefono")) . "'>Teléfono" . e($arrow("telefono")) . "</a></th>";

  echo "<th>Línea</th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("dia")) . "'>Día" . e($arrow("dia")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("hora")) . "'>Hora" . e($arrow("hora")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("duracion")) . "'>Duración" . e($arrow("duracion")) . "</a></th>";

  echo "<th><a class='sort' href='" . e($makeSortUrl("campo")) . "'>Campo" . e($arrow("campo")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("tipo")) . "'>Tipo" . e($arrow("tipo")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("precio")) . "'>Precio/h" . e($arrow("precio")) . "</a></th>";

  echo "<th>Acciones</th>";
  echo "</tr>";

  if ($res && $res->num_rows > 0) {
    while ($r = $res->fetch_assoc()) {
      $cliente = trim((string)$r["cliente_nombre"] . " " . (string)$r["cliente_apellidos"]);
      if ($cliente === "") $cliente = "-";

      $linea_id = (int)($r["linea_id"] ?? 0);
      $reserva_id = (int)($r["reserva_id"] ?? 0);

      $estado = strtolower(trim((string)($r["estado"] ?? "pendiente")));
      if ($estado === "") $estado = "pendiente";

      echo "<tr>";
      echo "<td>" . e($r["reserva_id"]) . "</td>";
      echo "<td>" . e($r["fecha"]) . "</td>";
      echo "<td>" . estado_badge($estado) . "</td>";
      echo "<td>" . e($cliente) . "</td>";
      echo "<td>" . e($r["cliente_email"]) . "</td>";
      echo "<td>" . e($r["cliente_telefono"]) . "</td>";

      echo "<td>" . e($r["linea_id"]) . "</td>";
      echo "<td>" . e($r["dia"]) . "</td>";
      echo "<td>" . e($r["hora"]) . "</td>";
      echo "<td>" . e($r["duracion"]) . "</td>";

      echo "<td>" . e($r["campo_nombre"]) . "</td>";
      echo "<td>" . e($r["campo_tipo"]) . "</td>";
      echo "<td>" . e($r["campo_precio_hora"]) . "</td>";

      echo "<td style='white-space:nowrap; display:flex; gap:8px; flex-wrap:wrap;'>";

      // Acciones de vista/edición por línea
      if ($linea_id > 0) {
        echo "<a class='btn' href='?tabla=reserva&accion=ver&linea_id=" . e($linea_id) . "'>Ver</a>";
        echo "<a class='btn' href='?tabla=reserva&accion=editar&linea_id=" . e($linea_id) . "'>Editar</a>";
      } else {
        echo "<span style='opacity:.8;'>-</span>";
      }

      // Acciones de estado y eliminación definitiva (por reserva)
      // Usamos POST para no “ensuciar” la URL con acciones.
      if ($HAS_ESTADO && $reserva_id > 0) {

        // Confirmar (solo si pendiente)
        if ($estado === "pendiente") {
          echo "<form method='POST' action='?tabla=reserva' style='display:inline; margin:0;'>";
          echo "<input type='hidden' name='accion_reserva' value='confirmar'>";
          echo "<input type='hidden' name='reserva_id' value='" . e($reserva_id) . "'>";
          echo "<input type='hidden' name='return_url' value='" . e($return_url) . "'>";
          echo "<button class='btn' type='submit'>Confirmar</button>";
          echo "</form>";
        }

        // Cancelar (si no está cancelada)
        if ($estado !== "cancelada") {
          echo "<form method='POST' action='?tabla=reserva' style='display:inline; margin:0;'>";
          echo "<input type='hidden' name='accion_reserva' value='cancelar'>";
          echo "<input type='hidden' name='reserva_id' value='" . e($reserva_id) . "'>";
          echo "<input type='hidden' name='return_url' value='" . e($return_url) . "'>";
          echo "<button class='btn' type='submit' onclick='return confirm(\"¿Cancelar esta reserva?\");'>Cancelar</button>";
          echo "</form>";
        }

        // Eliminar definitivo (solo cancelada)
        if ($estado === "cancelada") {
          echo "<form method='POST' action='?tabla=reserva' style='display:inline; margin:0;'>";
          echo "<input type='hidden' name='accion_reserva' value='eliminar'>";
          echo "<input type='hidden' name='reserva_id' value='" . e($reserva_id) . "'>";
          echo "<input type='hidden' name='return_url' value='" . e($return_url) . "'>";
          echo "<button class='btn' type='submit' onclick='return confirm(\"Esto eliminará definitivamente la reserva y sus líneas. ¿Continuar?\");'>Eliminar</button>";
          echo "</form>";
        }
      }

      echo "</td>";
      echo "</tr>";
    }
  } else {
    // Colspan: ahora hay 14 columnas (incluyendo Estado)
    echo "<tr><td colspan='14'>No hay registros.</td></tr>";
  }

  echo "</table>";

  $st->close();
  return;
}

/* ============================
   17) CAMPOS — vista simple
   ============================ */
if ($tabla === "campo") {
  $res = $cn->query("SELECT * FROM campo ORDER BY id DESC");
  $head = $cn->query("SELECT * FROM campo LIMIT 0");
  $fields = $head ? $head->fetch_fields() : [];

  echo "<table>";
  if ($fields) {
    echo "<tr>";
    foreach ($fields as $f) echo "<th>" . e($f->name) . "</th>";
    echo "</tr>";
  }

  if ($res && $res->num_rows > 0) {
    while ($r = $res->fetch_assoc()) {
      echo "<tr>";
      foreach ($r as $v) echo "<td>" . e($v === null ? "-" : $v) . "</td>";
      echo "</tr>";
    }
  } else {
    echo "<tr><td colspan='" . (count($fields) ?: 1) . "'>No hay registros.</td></tr>";
  }
  echo "</table>";
  return;
}

/* Fallback */
echo "<div style='padding:12px; border-radius:12px; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12);'>Sin vista.</div>";
