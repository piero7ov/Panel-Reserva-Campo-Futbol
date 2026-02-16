<?php
/**
 * back/inc/tabla_actions.php
 * Lógica para manejar peticiones POST (Crear, Editar, Eliminar, Confirmar, Mantenimiento)
 */

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
   20) MANTENIMIENTO — Acciones POST
   ============================ */
if ($tabla === "mantenimiento" && isset($_POST["accion_mant"])) {
    $act = (string)$_POST["accion_mant"];
    
    // Config mantenimiento
    $BACKUP_DIR  = dirname(__DIR__) . "/backups"; 
    $BACKUP_KEEP = 10; 

    // Asegurar tabla logs para poder escribir en ella
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

    // Crear backup
    if ($act === "backup_create") {
      if (!ensure_dir($BACKUP_DIR)) {
        notice_html("<strong>Error:</strong> no se puede crear/escribir en <code>/backups</code>.", "err");
        log_event($cn, $HAS_LOG_TABLE, "error", "error", "No writable backups dir", ["dir" => $BACKUP_DIR]);
      } else {
        $fname = "backup_" . date("Ymd_His") . "_" . $DB_NAME . ".sql";
        $path  = $BACKUP_DIR . "/" . $fname;

        $err = "";
        $ok = dump_db($cn, (string)$DB_NAME, $path, $err);

        if (!$ok) {
          notice_html("<strong>Error:</strong> backup no generado.<br><small><code>" . e($err) . "</code></small>", "err");
          log_event($cn, $HAS_LOG_TABLE, "backup_create", "error", "Backup failed", ["file" => $fname, "error" => $err]);
        } else {
          notice_html("Backup creado: <strong>" . e($fname) . "</strong>", "ok");
          log_event($cn, $HAS_LOG_TABLE, "backup_create", "info", "Backup creado", ["file" => $fname, "size" => @filesize($path)]);

          // Cleanup por retención
          $files = list_backups($BACKUP_DIR);
          if (count($files) > $BACKUP_KEEP) {
            $toDelete = array_slice($files, $BACKUP_KEEP);
            foreach ($toDelete as $f) {
              if (@unlink($f["path"])) {
                log_event($cn, $HAS_LOG_TABLE, "backup_cleanup", "warn", "Backup eliminado por retención", ["file" => $f["name"]]);
              }
            }
          }
        }
      }
    }

    // Borrar backup manual
    if ($act === "backup_delete") {
      $file = safe_name((string)($_POST["file"] ?? ""));
      if ($file === "") {
        notice_html("<strong>Error:</strong> archivo inválido.", "err");
      } else {
        $path = $BACKUP_DIR . "/" . $file;
        if (!is_file($path)) {
          notice_html("<strong>Error:</strong> no existe ese backup.", "err");
        } else {
          if (@unlink($path)) {
            notice_html("Backup eliminado: <strong>" . e($file) . "</strong>", "warn");
            log_event($cn, $HAS_LOG_TABLE, "backup_delete", "warn", "Backup eliminado manualmente", ["file" => $file]);
          } else {
            notice_html("<strong>Error:</strong> no se pudo eliminar el backup.", "err");
            log_event($cn, $HAS_LOG_TABLE, "backup_delete", "error", "No se pudo eliminar backup", ["file" => $file]);
          }
        }
      }
    }
}
