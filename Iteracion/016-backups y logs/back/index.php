<?php
/**
 * index.php — Panel de control (back)
 * ------------------------------------------------------------
 * - Login basado en tabla "usuarios"
 * - Panel con vistas de tablas (include inc/tabla.php)
 * - CRUD (inicio): actualizar reserva + sus líneas (POST update_reserva)
 */

session_start();

/* ============================
   CONFIG BD
   ============================ */
$DB_HOST = "localhost";
$DB_USER = "reserva_empresa";
$DB_PASS = "Reservaempresa123_";
$DB_NAME = "reserva_empresa";

/* Mensaje para el login */
$mensaje_login = "";

/* ============================
   Flash message (panel)
   ============================ */
$flash = "";
if (isset($_SESSION["flash"])) {
  $flash = (string)$_SESSION["flash"];
  unset($_SESSION["flash"]);
}

/* ============================
   LOGOUT (cerrar sesión)
   ============================ */
if (isset($_GET["logout"])) {
  unset($_SESSION["usuario"], $_SESSION["usuario_id"]);
  session_destroy();
  header("Location: ?");
  exit;
}

/* ============================
   LOGIN (contra BD: tabla usuarios)
   ============================ */
if (isset($_POST["usuario"]) && !isset($_POST["action"])) {
  $u = trim((string)($_POST["usuario"] ?? ""));
  $p = (string)($_POST["contrasena"] ?? "");

  $cn = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

  if ($cn->connect_errno) {
    $mensaje_login = "Error de conexión a la base de datos.";
  } else {
    $cn->set_charset("utf8mb4");

    $stmt = $cn->prepare("SELECT id, usuario, password_hash FROM usuarios WHERE usuario = ? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("s", $u);
      $stmt->execute();
      $stmt->store_result();

      if ($stmt->num_rows === 1) {
        $stmt->bind_result($id, $usuario_db, $hash_db);
        $stmt->fetch();

        if (password_verify($p, $hash_db)) {
          $_SESSION["usuario"] = $usuario_db;
          $_SESSION["usuario_id"] = (int)$id;
          header("Location: ?");
          exit;
        }
      }

      $mensaje_login = "Usuario o contraseña incorrectos.";
      $stmt->close();
    } else {
      $mensaje_login = "Error preparando la consulta de login.";
    }

    $cn->close();
  }
}

/* ============================
   CRUD (inicio): UPDATE Reserva
   - Solo si hay sesión válida
   - Se ejecuta ANTES del HTML para poder hacer header("Location")
   ============================ */
$logueado = isset($_SESSION["usuario"]) && $_SESSION["usuario"] !== "";

if ($logueado && isset($_POST["action"]) && $_POST["action"] === "update_reserva") {

  // Normalizamos + validamos lo mínimo
  $reserva_id = (int)($_POST["reserva_id"] ?? 0);
  $fecha      = trim((string)($_POST["fecha"] ?? ""));
  $cliente_id = (int)($_POST["cliente_id"] ?? 0);

  if ($reserva_id <= 0 || $cliente_id <= 0 || $fecha === "") {
    $_SESSION["flash"] = "No se pudo actualizar: faltan datos.";
    header("Location: ?tabla=reserva&edit=".$reserva_id);
    exit;
  }

  $cn = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
  if ($cn->connect_errno) {
    $_SESSION["flash"] = "Error de conexión a la base de datos.";
    header("Location: ?tabla=reserva&edit=".$reserva_id);
    exit;
  }

  $cn->set_charset("utf8mb4");

  // Usamos transacción para que cabecera + líneas se actualicen juntas
  $cn->begin_transaction();

  try {
    /* -----------------------------------------
       1) Actualizar cabecera reserva
       ----------------------------------------- */
    $stmt = $cn->prepare("UPDATE reserva SET fecha = ?, cliente_id = ? WHERE id = ? LIMIT 1");
    if (!$stmt) {
      throw new Exception("Error preparando UPDATE reserva.");
    }
    $stmt->bind_param("sii", $fecha, $cliente_id, $reserva_id);
    $stmt->execute();
    $stmt->close();

    /* -----------------------------------------
       2) Actualizar líneas (si vienen en el POST)
       - Actualizamos SOLO las líneas existentes
       - Verificamos que pertenecen a la reserva
       ----------------------------------------- */
    $linea_ids = $_POST["linea_id"] ?? [];
    $dias      = $_POST["dia"] ?? [];
    $horas     = $_POST["hora"] ?? [];
    $durs      = $_POST["duracion"] ?? [];
    $campo_ids = $_POST["campo_id_linea"] ?? [];

    if (is_array($linea_ids) && count($linea_ids) > 0) {

      $stmtLine = $cn->prepare("
        UPDATE lineareserva
        SET dia = ?, hora = ?, duracion = ?, campo_id = ?
        WHERE id = ? AND reserva_id = ?
        LIMIT 1
      ");
      if (!$stmtLine) {
        throw new Exception("Error preparando UPDATE lineareserva.");
      }

      // Recorremos por índice (todas las arrays deben ir alineadas)
      $n = count($linea_ids);
      for ($i = 0; $i < $n; $i++) {
        $lid = (int)($linea_ids[$i] ?? 0);
        if ($lid <= 0) continue;

        $dia  = trim((string)($dias[$i] ?? ""));
        $hora = trim((string)($horas[$i] ?? ""));
        $dur  = trim((string)($durs[$i] ?? ""));
        $cid  = (int)($campo_ids[$i] ?? 0);

        // Validación mínima (puedes endurecer luego)
        if ($dia === "" || $hora === "" || $dur === "" || $cid <= 0) {
          continue; // si falta algo en una línea, la saltamos
        }

        $stmtLine->bind_param("sssiii", $dia, $hora, $dur, $cid, $lid, $reserva_id);
        $stmtLine->execute();
      }

      $stmtLine->close();
    }

    // OK => commit
    $cn->commit();
    $cn->close();

    $_SESSION["flash"] = "Reserva actualizada correctamente.";
    header("Location: ?tabla=reserva&ver=".$reserva_id);
    exit;

  } catch (Exception $e) {
    // Error => rollback
    $cn->rollback();
    $cn->close();

    $_SESSION["flash"] = "No se pudo actualizar la reserva.";
    header("Location: ?tabla=reserva&edit=".$reserva_id);
    exit;
  }
}

/* ============================
   RENDER: Panel si hay sesión
   ============================ */
if ($logueado) {
?>
<!doctype html>
<html lang="es">
  <head>
    <meta charset="utf-8">
    <title>Panel de control</title>
    <link rel="stylesheet" href="css/panel.css">
  </head>

  <body>
    <nav>
      <a href="?tabla=reserva">Reservas</a>
      <a href="?tabla=campo">Campos</a>
      <a href="?tabla=cliente">Clientes</a>
      <a href="?tabla=mantenimiento">Mantenimiento</a>


      <a class="logout" href="?logout=1">Cerrar sesión</a>
    </nav>

    <main>
      <?php if ($flash !== ""): ?>
        <div class="flash"><?php echo htmlspecialchars($flash, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"); ?></div>
      <?php endif; ?>

      <?php include __DIR__ . "/inc/tabla.php"; ?>
    </main>
  </body>
</html>
<?php
} else {
  include __DIR__ . "/inc/login.php";
}
?>
