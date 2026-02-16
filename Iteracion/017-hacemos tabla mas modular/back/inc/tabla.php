<?php
/**
 * inc/tabla.php — Panel Reservas (JOIN) + CRUD (Insertar / Editar) + Clientes (Alta)
 * + Estados de reserva (reserva/confirmada/cancelada)
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
   1) Helpers & Config
   ============================ */
require_once __DIR__ . "/tabla_helpers.php";
require_once __DIR__ . "/smtp.php";

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
   Actions (POST Logic)
   ============================ */
require_once __DIR__ . "/tabla_actions.php";

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
   Views Routing
   ============================ */

// 13) CLIENTES — NUEVO (form)
if ($tabla === "cliente" && $accion === "nuevo") {
    require __DIR__ . "/views/cliente_form.php";
    return;
}

// 14) RESERVAS — NUEVA (form)
if ($tabla === "reserva" && $accion === "nueva") {
    require __DIR__ . "/views/reserva_form.php";
    return;
}

// 15) RESERVAS — VER (detalle)
if ($tabla === "reserva" && $accion === "ver") {
    require __DIR__ . "/views/reserva_detail.php";
    return;
}

// 16) RESERVAS — EDITAR (form)
if ($tabla === "reserva" && $accion === "editar") {
    require __DIR__ . "/views/reserva_edit.php";
    return;
}

// 17) CLIENTES — listado
if ($tabla === "cliente") {
    require __DIR__ . "/views/cliente_list.php";
    return;
}

// 18) RESERVAS — listado
if ($tabla === "reserva") {
    require __DIR__ . "/views/reserva_list.php";
    return;
}

// 19) CAMPOS — vista simple
if ($tabla === "campo") {
    require __DIR__ . "/views/campo_list.php";
    return;
}

// 20) MANTENIMIENTO — Backups + Logs
if ($tabla === "mantenimiento") {
    require __DIR__ . "/views/mantenimiento.php";
    return;
}

/* ============================
   21) Fallback
   ============================ */
notice_html("Sin vista.", "warn");
