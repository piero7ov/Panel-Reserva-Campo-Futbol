<?php
/**
 * inc/tabla.php — Panel Reservas (JOIN) + CRUD (Insertar / Editar) + Clientes (Alta)
 * + Estados de reserva (pendiente/confirmada/cancelada)
 * + Confirmación por SMTP (sockets) al pasar a "confirmada"
 * + Mantenimiento: Backups + Logs (mantenimiento_log)
 *
 * Modelo de datos:
 *  - cliente(id, nombre, apellidos, email, telefono)
 *  - campo(id, nombre, tipo, descripcion, precio_hora, imagen)
 *  - reserva(id, fecha, cliente_id, estado?)   <-- estado opcional (detectamos si existe)
 *  - lineareserva(id, reserva_id, campo_id, dia, hora, duracion)
 *
 * Importante:
 * - Este include se ejecuta dentro del HTML ya impreso por index.php (dentro de <main>).
 * - Evitamos header("Location: ...") porque a veces ya hay salida HTML; usamos redirección por JavaScript.
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

/** Avisos (usa .notice del CSS; coloreamos con inline) */
function notice_html(string $html, string $type = "info"): void {
  $style = "";
  if ($type === "ok") {
    $style = "border-color: rgba(34,197,94,.25); background: rgba(34,197,94,.10);";
  } elseif ($type === "warn") {
    $style = "border-color: rgba(251,191,36,.25); background: rgba(251,191,36,.10);";
  } elseif ($type === "err") {
    $style = "border-color: rgba(239,68,68,.25); background: rgba(239,68,68,.10);";
  }
  echo "<div class='notice' style='{$style}'>" . $html . "</div>";
}

/** Badge por estado (aprovecha tu CSS .badge.*) */
function estado_badge(string $estado): string {
  $estado = strtolower(trim($estado));
  if ($estado !== "confirmada" && $estado !== "cancelada") $estado = "pendiente";

  $label = $estado;
  $label = mb_strtoupper(mb_substr($label, 0, 1), "UTF-8") . mb_substr($label, 1);

  return "<span class='badge " . e($estado) . "'>" . e($label) . "</span>";
}

/* ============================
   2) Tablas / vistas permitidas
   ============================ */
$TABLAS_PERMITIDAS = [
  "reserva"       => "Reservas",
  "campo"         => "Campos",
  "cliente"       => "Clientes",
  "mantenimiento" => "Mantenimiento",
];

$tabla  = $_GET["tabla"] ?? "reserva";
$accion = $_GET["accion"] ?? "";
if (!isset($TABLAS_PERMITIDAS[$tabla])) $tabla = "reserva";

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
   4) SMTP (sockets) + include
   ============================ */
require_once __DIR__ . "/smtp.php";

/**
 * Ajusta estos valores a tu proveedor.
 * En Gmail: normalmente necesitas "App Password" (si tienes 2FA).
 */
$SMTP_HOST = $SMTP_HOST ?? "smtp.gmail.com";
$SMTP_PORT = $SMTP_PORT ?? 587; // STARTTLS
$SMTP_USER = $SMTP_USER ?? "[EMAIL_ADDRESS]";
$SMTP_PASS = $SMTP_PASS ?? "";  // App Password en Gmail (recomendado)
$SMTP_FROM_EMAIL = $SMTP_FROM_EMAIL ?? $SMTP_USER;
$SMTP_FROM_NAME  = $SMTP_FROM_NAME  ?? "Reservas";

/* ============================
   5) Detectar si existe columna "estado" en reserva
   ============================ */
$HAS_ESTADO = false;
if ($resCol = $cn->query("SHOW COLUMNS FROM reserva LIKE 'estado'")) {
  $HAS_ESTADO = ($resCol->num_rows > 0);
  $resCol->free();
}

/* ============================
   6) Datos auxiliares (selects)
   ============================ */
function getClientes(mysqli $cn): array {
  $out = [];
  $sql = "SELECT id, nombre, apellidos, email, telefono FROM cliente ORDER BY id DESC";
  if ($res = $cn->query($sql)) while ($r = $res->fetch_assoc()) $out[] = $r;
  return $out;
}

function getCampos(mysqli $cn): array {
  $out = [];
  $sql = "SELECT id, nombre, tipo, precio_hora FROM campo ORDER BY id DESC";
  if ($res = $cn->query($sql)) while ($r = $res->fetch_assoc()) $out[] = $r;
  return $out;
}

/* ============================
   7) RESERVAS — acciones: confirmar/cancelar/eliminar
   ============================ */
if ($tabla === "reserva" && isset($_POST["accion_reserva"])) {

  $accion_reserva = (string)($_POST["accion_reserva"] ?? "");
  $reserva_id = to_int($_POST["reserva_id"] ?? 0);
  $return_url = (string)($_POST["return_url"] ?? "?tabla=reserva");

  if ($reserva_id <= 0) {
    notice_html("<strong>Error:</strong> Reserva inválida.", "err");

  } elseif (!$HAS_ESTADO && in_array($accion_reserva, ["confirmar", "cancelar", "eliminar"], true)) {
    notice_html("<strong>Error:</strong> Falta la columna <code>estado</code> en <code>reserva</code>.", "err");

  } else {

    // Estado actual
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

    /* ============ CONFIRMAR ============ */
    if ($accion_reserva === "confirmar") {

      if ($estado_actual === "cancelada") {
        js_redirect($return_url . "&ok=estado_no_permitido");
      }

      if (trim((string)$SMTP_PASS) === "") {
        notice_html(
          "<strong>No se pudo enviar el correo.</strong><br>Falta configurar <code>\$SMTP_PASS</code>.<br><strong>No se confirmó</strong> la reserva.",
          "err"
        );
      } else {

        $sqlInfo = "
          SELECT
            r.id AS reserva_id,
            r.fecha AS fecha,
            c.nombre AS cliente_nombre,
            c.apellidos AS cliente_apellidos,
            c.email AS cliente_email,
            c.telefono AS cliente_telefono,
            lr.dia AS dia,
            lr.hora AS hora,
            lr.duracion AS duracion,
            ca.nombre AS campo_nombre,
            ca.tipo AS campo_tipo,
            ca.precio_hora AS campo_precio_hora
          FROM reserva r
          LEFT JOIN cliente c ON c.id = r.cliente_id
          LEFT JOIN lineareserva lr ON lr.reserva_id = r.id
          LEFT JOIN campo ca ON ca.id = lr.campo_id
          WHERE r.id = ?
          ORDER BY lr.id ASC
          LIMIT 1
        ";
        $stI = $cn->prepare($sqlInfo);
        $stI->bind_param("i", $reserva_id);
        $stI->execute();
        $resI = $stI->get_result();
        $info = $resI ? $resI->fetch_assoc() : null;
        $stI->close();

        if (!$info) {
          notice_html("<strong>Error:</strong> no se encontró información de la reserva. <strong>No se confirmó</strong>.", "err");
        } else {
          $to = trim((string)($info["cliente_email"] ?? ""));
          if ($to === "") {
            notice_html("<strong>No se pudo enviar el correo.</strong><br>El cliente no tiene email. <strong>No se confirmó</strong> la reserva.", "err");
          } else {

            $clienteNombre = trim((string)$info["cliente_nombre"] . " " . (string)$info["cliente_apellidos"]);
            if ($clienteNombre === "") $clienteNombre = "Cliente";

            $subject = "✅ Reserva confirmada #" . (int)$info["reserva_id"];

            $body  = "Hola {$clienteNombre},\n\n";
            $body .= "Tu reserva ha sido CONFIRMADA.\n\n";
            $body .= "Reserva: #" . (int)$info["reserva_id"] . "\n";
            $body .= "Fecha (registro): " . (string)$info["fecha"] . "\n";
            $body .= "Día/Hora: " . (string)$info["dia"] . " " . (string)$info["hora"] . "\n";
            $body .= "Duración: " . (string)$info["duracion"] . "\n";
            $body .= "Campo: " . (string)$info["campo_nombre"] . " (" . (string)$info["campo_tipo"] . ")\n";
            $body .= "Precio/h: " . (string)$info["campo_precio_hora"] . "\n\n";
            $body .= "Saludos,\n" . (string)$SMTP_FROM_NAME . "\n";

            $smtpErr = "";
            $sent = smtp_send_gmail(
              $SMTP_HOST,
              (int)$SMTP_PORT,
              $SMTP_USER,
              $SMTP_PASS,
              $SMTP_FROM_EMAIL,
              $SMTP_FROM_NAME,
              $to,
              $subject,
              $body,
              $smtpErr
            );

            if (!$sent) {
              notice_html(
                "<strong>No se pudo enviar el correo.</strong> No se confirmó la reserva.<br><small><code>" . e($smtpErr) . "</code></small>",
                "err"
              );
            } else {
              try {
                $nuevo = "confirmada";
                $st = $cn->prepare("UPDATE reserva SET estado = ? WHERE id = ?");
                $st->bind_param("si", $nuevo, $reserva_id);
                $st->execute();
                $st->close();

                js_redirect($return_url . "&ok=estado_confirmada");
              } catch (Throwable $ex) {
                notice_html("<strong>Error:</strong> el correo se envió, pero no se pudo confirmar en BD.", "err");
              }
            }
          }
        }
      }

    /* ============ CANCELAR ============ */
    } elseif ($accion_reserva === "cancelar") {

      try {
        $nuevo = "cancelada";
        $st = $cn->prepare("UPDATE reserva SET estado = ? WHERE id = ?");
        $st->bind_param("si", $nuevo, $reserva_id);
        $st->execute();
        $st->close();

        js_redirect($return_url . "&ok=estado_cancelada");
      } catch (Throwable $ex) {
        notice_html("<strong>Error:</strong> no se pudo cancelar.", "err");
      }

    /* ============ ELIMINAR DEFINITIVO ============ */
    } elseif ($accion_reserva === "eliminar") {

      if ($estado_actual !== "cancelada") {
        js_redirect($return_url . "&ok=eliminar_solo_cancelada");
      }

      $cn->begin_transaction();
      try {
        $st1 = $cn->prepare("DELETE FROM lineareserva WHERE reserva_id = ?");
        $st1->bind_param("i", $reserva_id);
        $st1->execute();
        $st1->close();

        $st2 = $cn->prepare("DELETE FROM reserva WHERE id = ? AND estado = 'cancelada'");
        $st2->bind_param("i", $reserva_id);
        $st2->execute();
        $st2->close();

        $cn->commit();
        js_redirect($return_url . "&ok=eliminada");

      } catch (Throwable $ex) {
        $cn->rollback();
        notice_html("<strong>Error:</strong> no se pudo eliminar definitivamente.", "err");
      }

    } else {
      notice_html("<strong>Error:</strong> acción desconocida.", "err");
    }
  }
}

/* ============================
   8) CLIENTES — INSERTAR
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
    $html = "<strong>Revisa:</strong><br>";
    foreach ($errores as $er) $html .= "• " . e($er) . "<br>";
    notice_html($html, "err");
  } else {
    try {
      $st = $cn->prepare("INSERT INTO cliente (nombre, apellidos, email, telefono) VALUES (?, ?, ?, ?)");
      $st->bind_param("ssss", $nombre, $apellidos, $email, $telefono);
      $st->execute();
      $st->close();

      js_redirect("?tabla=cliente&ok=cliente_creado");
    } catch (Throwable $ex) {
      notice_html("<strong>Error:</strong> no se pudo crear el cliente.", "err");
    }
  }
}

/* ============================
   9) RESERVAS — INSERTAR (reserva + 1 línea)
   ============================ */
if ($tabla === "reserva" && isset($_POST["crear_reserva"])) {

  $cliente_nuevo = isset($_POST["cliente_nuevo"]) && (string)$_POST["cliente_nuevo"] === "1";
  $cliente_id = to_int($_POST["cliente_id"] ?? 0);

  $nuevo_nombre    = trim((string)($_POST["nuevo_nombre"] ?? ""));
  $nuevo_apellidos = trim((string)($_POST["nuevo_apellidos"] ?? ""));
  $nuevo_email     = trim((string)($_POST["nuevo_email"] ?? ""));
  $nuevo_telefono  = trim((string)($_POST["nuevo_telefono"] ?? ""));

  $fecha    = normalizarDatetimeLocal((string)($_POST["fecha"] ?? ""));
  $campo_id = to_int($_POST["campo_id"] ?? 0);
  $dia      = trim((string)($_POST["dia"] ?? ""));
  $hora     = trim((string)($_POST["hora"] ?? ""));
  $duracion = trim((string)($_POST["duracion"] ?? ""));

  $errores = [];

  if ($cliente_nuevo) {
    if ($nuevo_nombre === "") $errores[] = "En cliente nuevo: debes indicar el nombre.";
    if ($nuevo_email === "" && $nuevo_telefono === "") $errores[] = "En cliente nuevo: debes indicar email o teléfono (al menos uno).";
  } else {
    if ($cliente_id <= 0) $errores[] = "Debes seleccionar un cliente.";
  }

  if ($fecha === "")    $errores[] = "Debes indicar la fecha de la reserva.";
  if ($campo_id <= 0)   $errores[] = "Debes seleccionar un campo.";
  if ($dia === "")      $errores[] = "Debes indicar el día.";
  if ($hora === "")     $errores[] = "Debes indicar la hora.";
  if ($duracion === "") $errores[] = "Debes indicar la duración.";

  if (count($errores) > 0) {
    $html = "<strong>Revisa:</strong><br>";
    foreach ($errores as $er) $html .= "• " . e($er) . "<br>";
    notice_html($html, "err");
  } else {

    $cn->begin_transaction();
    try {
      if ($cliente_nuevo) {
        $stC = $cn->prepare("INSERT INTO cliente (nombre, apellidos, email, telefono) VALUES (?, ?, ?, ?)");
        $stC->bind_param("ssss", $nuevo_nombre, $nuevo_apellidos, $nuevo_email, $nuevo_telefono);
        $stC->execute();
        $cliente_id = (int)$cn->insert_id;
        $stC->close();
      }

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

      $stL = $cn->prepare("INSERT INTO lineareserva (reserva_id, campo_id, dia, hora, duracion) VALUES (?, ?, ?, ?, ?)");
      $stL->bind_param("iisss", $reserva_id, $campo_id, $dia, $hora, $duracion);
      $stL->execute();
      $stL->close();

      $cn->commit();
      js_redirect("?tabla=reserva&ok=creado");

    } catch (Throwable $ex) {
      $cn->rollback();
      notice_html("<strong>Error:</strong> no se pudo crear la reserva.", "err");
    }
  }
}

/* ============================
   10) RESERVAS — EDITAR / ACTUALIZAR
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
    $html = "<strong>Revisa:</strong><br>";
    foreach ($errores as $er) $html .= "• " . e($er) . "<br>";
    notice_html($html, "err");
  } else {
    $cn->begin_transaction();
    try {
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
      notice_html("<strong>Error:</strong> no se pudo actualizar.", "err");
    }
  }
}

/* ============================
   11) Título
   ============================ */
echo "<h2 style='margin:0 0 14px; font-size:18px; letter-spacing:.2px;'>" . e($TABLAS_PERMITIDAS[$tabla]) . "</h2>";

/* ============================
   12) Mensajes OK
   ============================ */
if (isset($_GET["ok"])) {
  $ok = (string)$_GET["ok"];
  $msg = "";
  $type = "ok";

  if ($ok === "creado")                  $msg = "Reserva creada correctamente.";
  if ($ok === "editado")                 $msg = "Reserva actualizada correctamente.";
  if ($ok === "cliente_creado")          $msg = "Cliente creado correctamente.";

  if ($ok === "estado_confirmada")       $msg = "Reserva confirmada (correo enviado).";
  if ($ok === "estado_cancelada")        { $msg = "Reserva cancelada."; $type = "warn"; }
  if ($ok === "eliminada")               { $msg = "Reserva eliminada definitivamente."; $type = "warn"; }

  if ($ok === "estado_no_permitido")     { $msg = "No puedes confirmar una reserva cancelada."; $type = "warn"; }
  if ($ok === "eliminar_solo_cancelada") { $msg = "Solo puedes eliminar definitivamente reservas canceladas."; $type = "warn"; }

  if ($msg !== "") notice_html(e($msg), $type);
}

/* ============================
   13) CLIENTES — NUEVO (form)
   ============================ */
if ($tabla === "cliente" && $accion === "nuevo") {

  echo "<div style='margin:0 0 12px; display:flex; gap:10px; align-items:center;'>";
  echo "<a class='btn' href='?tabla=cliente'>← Volver</a>";
  echo "</div>";

  echo "<form method='POST' action='?tabla=cliente&accion=nuevo' class='filters'>";
  echo "<input type='hidden' name='crear_cliente' value='1'>";

  echo "<div class='field grow'><label>Nombre</label><input type='text' name='nombre' placeholder='Nombre' required></div>";
  echo "<div class='field grow'><label>Apellidos</label><input type='text' name='apellidos' placeholder='Apellidos'></div>";
  echo "<div class='field grow'><label>Email</label><input type='text' name='email' placeholder='Email (opcional si hay teléfono)'></div>";
  echo "<div class='field'><label>Teléfono</label><input type='text' name='telefono' placeholder='Teléfono (opcional si hay email)'></div>";

  echo "<div class='actions'>";
  echo "<button class='btn primary' type='submit'>Crear</button>";
  echo "<a class='btn' href='?tabla=cliente'>Cancelar</a>";
  echo "</div>";

  echo "</form>";
  return;
}

/* ============================
   14) RESERVAS — NUEVA (form)
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

  echo "<div class='field'>";
  echo "<label>Cliente nuevo</label>";
  echo "<div style='display:flex; gap:8px; align-items:center; padding:10px 12px; border-radius:12px; border:1px solid rgba(255,255,255,.14); background:rgba(255,255,255,.06);'>";
  echo "<input type='checkbox' id='cliente_nuevo' name='cliente_nuevo' value='1'>";
  echo "<label for='cliente_nuevo' style='margin:0; font-size:14px; opacity:.95;'>Crear cliente nuevo</label>";
  echo "</div>";
  echo "</div>";

  echo "<div class='field grow'><label>Nombre (cliente nuevo)</label><input type='text' name='nuevo_nombre' placeholder='Nombre'></div>";
  echo "<div class='field grow'><label>Apellidos (cliente nuevo)</label><input type='text' name='nuevo_apellidos' placeholder='Apellidos'></div>";
  echo "<div class='field grow'><label>Email (cliente nuevo)</label><input type='text' name='nuevo_email' placeholder='Email (opcional si hay teléfono)'></div>";
  echo "<div class='field'><label>Teléfono (cliente nuevo)</label><input type='text' name='nuevo_telefono' placeholder='Teléfono (opcional si hay email)'></div>";

  echo "<div class='field'><label>Fecha (reserva)</label><input type='datetime-local' name='fecha' value='" . e($fecha_default) . "' required></div>";

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

  echo "<div class='field'><label>Día</label><input type='date' name='dia' value='" . e($dia_default) . "' required></div>";
  echo "<div class='field'><label>Hora</label><input type='time' name='hora' value='15:00' required></div>";
  echo "<div class='field'><label>Duración</label><input type='text' name='duracion' value='1' placeholder='Ej: 1 / 2' required></div>";

  echo "<div class='actions'>";
  echo "<button class='btn primary' type='submit'>Crear</button>";
  echo "<a class='btn' href='?tabla=reserva'>Cancelar</a>";
  echo "</div>";

  echo "</form>";
  return;
}

/* ============================
   15) RESERVAS — VER (detalle)
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

  if (!$row) { notice_html("No encontrado.", "warn"); return; }

  $cliente = trim((string)$row["cliente_nombre"] . " " . (string)$row["cliente_apellidos"]);
  $estado  = strtolower(trim((string)($row["estado"] ?? "pendiente")));
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
   16) RESERVAS — EDITAR (form)
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

  if (!$row) { notice_html("No encontrado.", "warn"); return; }

  $clientes = getClientes($cn);
  $campos   = getCampos($cn);

  $fecha_val = (string)$row["fecha"];
  $fecha_val = str_replace(" ", "T", $fecha_val);
  $fecha_val = preg_replace('/:\d{2}$/', '', $fecha_val);

  $estado  = strtolower(trim((string)($row["estado"] ?? "pendiente")));
  if ($estado === "") $estado = "pendiente";

  notice_html("<strong>Estado:</strong> " . estado_badge($estado), "info");

  echo "<form method='POST' action='?tabla=reserva&accion=editar&linea_id=" . e($linea_id) . "' class='filters'>";
  echo "<input type='hidden' name='guardar_edicion' value='1'>";
  echo "<input type='hidden' name='linea_id' value='" . e($row["linea_id"]) . "'>";
  echo "<input type='hidden' name='reserva_id' value='" . e($row["reserva_id"]) . "'>";

  echo "<div class='field grow'><label>Cliente</label><select name='cliente_id' required>";
  foreach ($clientes as $c) {
    $selected = ((int)$c["id"] === (int)$row["cliente_id"]) ? " selected" : "";
    $label = trim($c["nombre"] . " " . $c["apellidos"]);
    $extra = trim((string)$c["email"]);
    $txt = "#" . $c["id"] . " - " . $label;
    if ($extra !== "") $txt .= " (" . $extra . ")";
    echo "<option value='" . e($c["id"]) . "'" . $selected . ">" . e($txt) . "</option>";
  }
  echo "</select></div>";

  echo "<div class='field'><label>Fecha (reserva)</label><input type='datetime-local' name='fecha' value='" . e($fecha_val) . "' required></div>";

  echo "<div class='field grow'><label>Campo</label><select name='campo_id' required>";
  foreach ($campos as $ca) {
    $selected = ((int)$ca["id"] === (int)$row["campo_id"]) ? " selected" : "";
    $txt = "#" . $ca["id"] . " - " . $ca["nombre"] . " · " . $ca["tipo"] . " · " . $ca["precio_hora"];
    echo "<option value='" . e($ca["id"]) . "'" . $selected . ">" . e($txt) . "</option>";
  }
  echo "</select></div>";

  echo "<div class='field'><label>Día</label><input type='date' name='dia' value='" . e($row["dia"]) . "' required></div>";
  echo "<div class='field'><label>Hora</label><input type='time' name='hora' value='" . e($row["hora"]) . "' required></div>";
  echo "<div class='field'><label>Duración</label><input type='text' name='duracion' value='" . e($row["duracion"]) . "' required></div>";

  echo "<div class='actions'>";
  echo "<button class='btn primary' type='submit'>Guardar</button>";
  echo "<a class='btn' href='?tabla=reserva'>Cancelar</a>";
  echo "</div>";

  echo "</form>";
  return;
}

/* ============================
   17) CLIENTES — listado
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

  echo "<div class='field grow'><label>Buscar cliente</label><input type='text' name='q' value='" . e($q) . "' placeholder='Nombre, email o teléfono'></div>";

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
   18) RESERVAS — listado
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
  ];
  if (!isset($SORT_MAP[$sort])) $sort = "reserva";

  $campos = getCampos($cn);

  echo "<div style='margin:0 0 10px; display:flex; gap:10px; align-items:center;'>";
  echo "<a class='btn primary' href='?tabla=reserva&accion=nueva'>+ Nueva reserva</a>";
  echo "</div>";

  echo "<form class='filters' method='GET' action='?'>";
  echo "<input type='hidden' name='tabla' value='reserva'>";

  echo "<div class='field grow'><label>Buscar cliente</label><input type='text' name='q' value='" . e($q) . "' placeholder='Nombre, email o teléfono'></div>";
  echo "<div class='field'><label>Fecha</label><input type='date' name='fecha' value='" . e($f_fecha) . "'></div>";

  echo "<div class='field grow'><label>Campo</label><select name='campo_id'>";
  echo "<option value='0'>Todos</option>";
  foreach ($campos as $ca) {
    $sel = ((int)$ca["id"] === $f_campo) ? " selected" : "";
    $txt = $ca["nombre"] . " · " . $ca["tipo"];
    echo "<option value='" . e($ca["id"]) . "'" . $sel . ">" . e($txt) . "</option>";
  }
  echo "</select></div>";

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

      $linea_id   = (int)($r["linea_id"] ?? 0);
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

      if ($linea_id > 0) {
        echo "<a class='btn sm' href='?tabla=reserva&accion=ver&linea_id=" . e($linea_id) . "'>Ver</a>";
        echo "<a class='btn sm' href='?tabla=reserva&accion=editar&linea_id=" . e($linea_id) . "'>Editar</a>";
      } else {
        echo "<span style='opacity:.8;'>-</span>";
      }

      if ($HAS_ESTADO && $reserva_id > 0) {

        if ($estado === "pendiente") {
          echo "<form method='POST' action='?tabla=reserva' style='display:inline; margin:0;'>";
          echo "<input type='hidden' name='accion_reserva' value='confirmar'>";
          echo "<input type='hidden' name='reserva_id' value='" . e($reserva_id) . "'>";
          echo "<input type='hidden' name='return_url' value='" . e($return_url) . "'>";
          echo "<button class='btn sm primary' type='submit' onclick='return confirm(\"¿Confirmar y enviar correo al cliente?\");'>Confirmar</button>";
          echo "</form>";
        }

        if ($estado !== "cancelada") {
          echo "<form method='POST' action='?tabla=reserva' style='display:inline; margin:0;'>";
          echo "<input type='hidden' name='accion_reserva' value='cancelar'>";
          echo "<input type='hidden' name='reserva_id' value='" . e($reserva_id) . "'>";
          echo "<input type='hidden' name='return_url' value='" . e($return_url) . "'>";
          echo "<button class='btn sm warn' type='submit' onclick='return confirm(\"¿Cancelar esta reserva?\");'>Cancelar</button>";
          echo "</form>";
        }

        if ($estado === "cancelada") {
          echo "<form method='POST' action='?tabla=reserva' style='display:inline; margin:0;'>";
          echo "<input type='hidden' name='accion_reserva' value='eliminar'>";
          echo "<input type='hidden' name='reserva_id' value='" . e($reserva_id) . "'>";
          echo "<input type='hidden' name='return_url' value='" . e($return_url) . "'>";
          echo "<button class='btn sm danger' type='submit' onclick='return confirm(\"Esto eliminará definitivamente la reserva y sus líneas. ¿Continuar?\");'>Eliminar</button>";
          echo "</form>";
        }
      }

      echo "</td>";
      echo "</tr>";
    }
  } else {
    echo "<tr><td colspan='14'>No hay registros.</td></tr>";
  }

  echo "</table>";

  $st->close();
  return;
}

/* ============================
   19) CAMPOS — vista simple
   ============================ */
if ($tabla === "campo") {

  $res  = $cn->query("SELECT * FROM campo ORDER BY id DESC");
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

/* ============================
   20) MANTENIMIENTO — Backups + Logs
   ============================ */
if ($tabla === "mantenimiento") {

  // ---- Config mantenimiento
  $BACKUP_DIR  = dirname(__DIR__) . "/backups"; // carpeta /backups al lado de index.php
  $BACKUP_KEEP = 10; // cuántos backups conservar (los más recientes)

  // ---- Crear tabla logs si no existe (no rompe si ya existe)
  $cn->query("
    CREATE TABLE IF NOT EXISTS mantenimiento_log (
      id INT AUTO_INCREMENT PRIMARY KEY,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      action VARCHAR(40) NOT NULL,
      level  VARCHAR(10) NOT NULL DEFAULT 'info',
      message TEXT NOT NULL,
      meta_json LONGTEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $HAS_LOG_TABLE = false;
  if ($r = $cn->query("SHOW TABLES LIKE 'mantenimiento_log'")) {
    $HAS_LOG_TABLE = ($r->num_rows > 0);
    $r->free();
  }

  $log_event = function(string $action, string $level, string $message, array $meta = []) use ($cn, $HAS_LOG_TABLE) {
    if (!$HAS_LOG_TABLE) return;
    $meta_json = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $st = $cn->prepare("INSERT INTO mantenimiento_log (action, level, message, meta_json) VALUES (?, ?, ?, ?)");
    $st->bind_param("ssss", $action, $level, $message, $meta_json);
    $st->execute();
    $st->close();
  };

  $human_size = function(int $bytes): string {
    $u = ["B","KB","MB","GB","TB"];
    $i = 0;
    $v = (float)$bytes;
    while ($v >= 1024 && $i < count($u)-1) { $v /= 1024; $i++; }
    return number_format($v, ($i===0?0:2), ".", "") . " " . $u[$i];
  };

  $ensure_dir = function(string $dir): bool {
    if (is_dir($dir)) return is_writable($dir);
    @mkdir($dir, 0775, true);
    return is_dir($dir) && is_writable($dir);
  };

  $safe_name = function(string $name): string {
    // Solo permitimos letras, números, guiones, underscores y punto
    return preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
  };

  $list_backups = function(string $dir): array {
    if (!is_dir($dir)) return [];
    $files = glob($dir . "/*.sql") ?: [];
    $out = [];
    foreach ($files as $f) {
      $out[] = [
        "name" => basename($f),
        "path" => $f,
        "mtime" => @filemtime($f) ?: 0,
        "size" => @filesize($f) ?: 0,
      ];
    }
    usort($out, fn($a,$b) => $b["mtime"] <=> $a["mtime"]);
    return $out;
  };

  // ---- Dump DB a .sql (sin mysqldump, todo en PHP)
  $dump_db = function(mysqli $cn, string $dbName, string $filePath, string &$err): bool {
    $err = "";
    $fp = @fopen($filePath, "wb");
    if (!$fp) { $err = "No se pudo crear el archivo de backup."; return false; }

    $now = date("Y-m-d H:i:s");
    fwrite($fp, "-- Backup DB: {$dbName}\n-- Generated: {$now}\n\n");
    fwrite($fp, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    // Tablas base
    $tables = [];
    $resT = $cn->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'");
    if (!$resT) { $err = "No se pudieron listar tablas."; fclose($fp); return false; }
    while ($row = $resT->fetch_array(MYSQLI_NUM)) $tables[] = (string)$row[0];
    $resT->free();

    foreach ($tables as $t) {
      // CREATE TABLE
      $resC = $cn->query("SHOW CREATE TABLE `{$t}`");
      if (!$resC) { $err = "No se pudo leer estructura de {$t}."; fclose($fp); return false; }
      $createRow = $resC->fetch_assoc();
      $resC->free();
      $createSql = $createRow["Create Table"] ?? "";

      fwrite($fp, "\n-- ----------------------------\n");
      fwrite($fp, "-- Table: {$t}\n");
      fwrite($fp, "-- ----------------------------\n");
      fwrite($fp, "DROP TABLE IF EXISTS `{$t}`;\n");
      fwrite($fp, $createSql . ";\n\n");

      // Detectar columnas binarias/blob para exportarlas como 0xHEX
      $binCols = [];
      $stCols = $cn->prepare("
        SELECT COLUMN_NAME, DATA_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
      ");
      $stCols->bind_param("ss", $dbName, $t);
      $stCols->execute();
      $rCols = $stCols->get_result();
      if ($rCols) {
        while ($c = $rCols->fetch_assoc()) {
          $dt = strtolower((string)$c["DATA_TYPE"]);
          if (in_array($dt, ["blob","longblob","mediumblob","tinyblob","binary","varbinary"], true)) {
            $binCols[(string)$c["COLUMN_NAME"]] = true;
          }
        }
      }
      $stCols->close();

      // DATA
      $resD = $cn->query("SELECT * FROM `{$t}`");
      if (!$resD) { $err = "No se pudo leer datos de {$t}."; fclose($fp); return false; }

      if ($resD->num_rows > 0) {
        $fields = $resD->fetch_fields();
        $colNames = array_map(fn($f) => "`" . $f->name . "`", $fields);
        $colList = implode(", ", $colNames);

        while ($r = $resD->fetch_assoc()) {
          $vals = [];
          foreach ($fields as $f) {
            $name = $f->name;
            $v = $r[$name];

            if ($v === null) {
              $vals[] = "NULL";
              continue;
            }

            if (isset($binCols[$name])) {
              $vals[] = "0x" . bin2hex((string)$v);
              continue;
            }

            $vals[] = "'" . $cn->real_escape_string((string)$v) . "'";
          }

          $valList = implode(", ", $vals);
          fwrite($fp, "INSERT INTO `{$t}` ({$colList}) VALUES ({$valList});\n");
        }
      }

      $resD->free();
      fwrite($fp, "\n");
    }

    fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fp);
    return true;
  };

  // ---- Acciones POST (crear backup / borrar backup)
  if (isset($_POST["accion_mant"])) {
    $act = (string)$_POST["accion_mant"];

    // Crear backup
    if ($act === "backup_create") {
      if (!$ensure_dir($BACKUP_DIR)) {
        notice_html("<strong>Error:</strong> no se puede crear/escribir en <code>/backups</code>.", "err");
        $log_event("error", "error", "No writable backups dir", ["dir" => $BACKUP_DIR]);
      } else {
        $fname = "backup_" . date("Ymd_His") . "_" . $DB_NAME . ".sql";
        $path  = $BACKUP_DIR . "/" . $fname;

        $err = "";
        $ok = $dump_db($cn, (string)$DB_NAME, $path, $err);

        if (!$ok) {
          notice_html("<strong>Error:</strong> backup no generado.<br><small><code>" . e($err) . "</code></small>", "err");
          $log_event("backup_create", "error", "Backup failed", ["file" => $fname, "error" => $err]);
        } else {
          notice_html("Backup creado: <strong>" . e($fname) . "</strong>", "ok");
          $log_event("backup_create", "info", "Backup creado", ["file" => $fname, "size" => @filesize($path)]);

          // Cleanup por retención
          $files = $list_backups($BACKUP_DIR);
          if (count($files) > $BACKUP_KEEP) {
            $toDelete = array_slice($files, $BACKUP_KEEP);
            foreach ($toDelete as $f) {
              if (@unlink($f["path"])) {
                $log_event("backup_cleanup", "warn", "Backup eliminado por retención", ["file" => $f["name"]]);
              }
            }
          }
        }
      }
    }

    // Borrar backup manual
    if ($act === "backup_delete") {
      $file = $safe_name((string)($_POST["file"] ?? ""));
      if ($file === "") {
        notice_html("<strong>Error:</strong> archivo inválido.", "err");
      } else {
        $path = $BACKUP_DIR . "/" . $file;
        if (!is_file($path)) {
          notice_html("<strong>Error:</strong> no existe ese backup.", "err");
        } else {
          if (@unlink($path)) {
            notice_html("Backup eliminado: <strong>" . e($file) . "</strong>", "warn");
            $log_event("backup_delete", "warn", "Backup eliminado manualmente", ["file" => $file]);
          } else {
            notice_html("<strong>Error:</strong> no se pudo eliminar el backup.", "err");
            $log_event("backup_delete", "error", "No se pudo eliminar backup", ["file" => $file]);
          }
        }
      }
    }
  }

  // ---- UI: Backups + Logs
  echo "<div style='display:flex; gap:18px; flex-wrap:wrap; align-items:flex-start;'>";

  /* ====== BLOQUE BACKUPS ====== */
  echo "<div style='flex:1; min-width:320px;'>";
  echo "<h3 style='margin:0 0 10px; font-size:15px;'>Backups</h3>";

  echo "<form method='POST' class='filters' style='margin:0 0 12px;'>";
  echo "<input type='hidden' name='accion_mant' value='backup_create'>";
  echo "<div class='actions'>";
  echo "<button class='btn primary' type='submit'>Crear backup ahora</button>";
  echo "</div>";
  echo "</form>";

  if (!is_dir($BACKUP_DIR)) {
    notice_html("No existe la carpeta <code>/backups</code>.", "warn");
  } elseif (!is_writable($BACKUP_DIR)) {
    notice_html("La carpeta <code>/backups</code> existe pero no tiene permisos de escritura.", "err");
  }

  $files = $list_backups($BACKUP_DIR);
  echo "<table>";
  echo "<tr><th>Archivo</th><th>Tamaño</th><th>Fecha</th><th>Acciones</th></tr>";

  if ($files) {
    foreach ($files as $f) {
      $date = $f["mtime"] ? date("Y-m-d H:i:s", $f["mtime"]) : "-";
      $size = (int)$f["size"];

      // Link público (requiere que /backups esté al lado de index.php y sea accesible)
      $publicLink = "backups/" . rawurlencode($f["name"]);

      echo "<tr>";
      echo "<td><code>" . e($f["name"]) . "</code></td>";
      echo "<td>" . e($human_size($size)) . "</td>";
      echo "<td>" . e($date) . "</td>";
      echo "<td style='white-space:nowrap; display:flex; gap:8px; flex-wrap:wrap;'>";
      echo "<a class='btn sm' href='" . e($publicLink) . "' download>Descargar</a>";

      echo "<form method='POST' style='margin:0; display:inline;'>";
      echo "<input type='hidden' name='accion_mant' value='backup_delete'>";
      echo "<input type='hidden' name='file' value='" . e($f["name"]) . "'>";
      echo "<button class='btn sm danger' type='submit' onclick='return confirm(\"¿Eliminar este backup?\");'>Eliminar</button>";
      echo "</form>";

      echo "</td>";
      echo "</tr>";
    }
  } else {
    echo "<tr><td colspan='4'>No hay backups aún.</td></tr>";
  }

  echo "</table>";
  echo "</div>";

  /* ====== BLOQUE LOGS ====== */
  echo "<div style='flex:1; min-width:320px;'>";
  echo "<h3 style='margin:0 0 10px; font-size:15px;'>Logs</h3>";

  if (!$HAS_LOG_TABLE) {
    notice_html("No existe la tabla <code>mantenimiento_log</code> (ejecuta el SQL que te pasé).", "warn");
  } else {
    $qlog = trim((string)($_GET["qlog"] ?? ""));
    $lvl  = trim((string)($_GET["lvl"] ?? ""));
    $act  = trim((string)($_GET["act"] ?? ""));

    echo "<form class='filters' method='GET' action='?'>";
    echo "<input type='hidden' name='tabla' value='mantenimiento'>";

    echo "<div class='field grow'><label>Buscar</label><input type='text' name='qlog' value='" . e($qlog) . "' placeholder='Mensaje...'></div>";

    echo "<div class='field'><label>Nivel</label><select name='lvl'>";
    echo "<option value=''>Todos</option>";
    foreach (["info","warn","error"] as $opt) {
      $sel = ($lvl === $opt) ? " selected" : "";
      echo "<option value='" . e($opt) . "'" . $sel . ">" . e($opt) . "</option>";
    }
    echo "</select></div>";

    echo "<div class='field'><label>Acción</label><select name='act'>";
    echo "<option value=''>Todas</option>";
    $actions = ["backup_create","backup_delete","backup_cleanup","error","info"];
    foreach ($actions as $opt) {
      $sel = ($act === $opt) ? " selected" : "";
      echo "<option value='" . e($opt) . "'" . $sel . ">" . e($opt) . "</option>";
    }
    echo "</select></div>";

    echo "<div class='actions'>";
    echo "<button class='btn primary' type='submit'>Aplicar</button>";
    echo "<a class='btn' href='?tabla=mantenimiento'>Limpiar</a>";
    echo "</div>";

    echo "</form>";

    $where = [];
    $types = "";
    $params = [];

    if ($qlog !== "") {
      $where[] = "(message LIKE ? OR meta_json LIKE ?)";
      $like = "%" . $qlog . "%";
      $types .= "ss";
      $params[] = $like; $params[] = $like;
    }
    if ($lvl !== "") {
      $where[] = "level = ?";
      $types .= "s";
      $params[] = $lvl;
    }
    if ($act !== "") {
      $where[] = "action = ?";
      $types .= "s";
      $params[] = $act;
    }

    $sqlL = "SELECT id, created_at, action, level, message, meta_json FROM mantenimiento_log";
    if ($where) $sqlL .= " WHERE " . implode(" AND ", $where);
    $sqlL .= " ORDER BY id DESC LIMIT 200";

    $stL = $cn->prepare($sqlL);
    if ($types !== "") $stL->bind_param($types, ...$params);
    $stL->execute();
    $resL = $stL->get_result();

    echo "<table>";
    echo "<tr><th>Fecha</th><th>Nivel</th><th>Acción</th><th>Mensaje</th></tr>";

    if ($resL && $resL->num_rows > 0) {
      while ($r = $resL->fetch_assoc()) {
        $lvlClass = strtolower((string)$r["level"]);
        $badge = "<span class='badge " . e($lvlClass === "error" ? "cancelada" : ($lvlClass === "warn" ? "pendiente" : "confirmada")) . "'>" . e($lvlClass) . "</span>";
        echo "<tr>";
        echo "<td>" . e($r["created_at"]) . "</td>";
        echo "<td>" . $badge . "</td>";
        echo "<td><code>" . e($r["action"]) . "</code></td>";
        echo "<td>" . e($r["message"]) . "</td>";
        echo "</tr>";
      }
    } else {
      echo "<tr><td colspan='4'>Sin logs.</td></tr>";
    }

    echo "</table>";
    $stL->close();
  }

  echo "</div>";
  echo "</div>";

  return;
}

/* ============================
   21) Fallback
   ============================ */
notice_html("Sin vista.", "warn");
