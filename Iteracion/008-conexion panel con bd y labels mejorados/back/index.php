<?php
/**
 * index.php — Panel de control (back)
 * ------------------------------------------------------------
 * Login basado en tabla "usuarios" (BD), sin credenciales hardcodeadas.
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
   LOGOUT (cerrar sesión)
   ============================ */
if (isset($_GET["logout"])) {
  // Borramos datos de sesión y volvemos al login
  unset($_SESSION["usuario"], $_SESSION["usuario_id"]);
  session_destroy();
  header("Location: ?");
  exit;
}

/* ============================
   LOGIN (contra BD: tabla usuarios)
   ============================ */
if (isset($_POST["usuario"])) {
  // Normalizamos entrada del formulario
  $u = trim((string)($_POST["usuario"] ?? ""));
  $p = (string)($_POST["contrasena"] ?? "");

  // Conectamos a BD solo para validar credenciales
  $cn = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

  if ($cn->connect_errno) {
    // Error de conexión: mostramos mensaje genérico
    $mensaje_login = "Error de conexión a la base de datos.";
  } else {
    $cn->set_charset("utf8mb4");

    // Consulta preparada para buscar el usuario
    $stmt = $cn->prepare("SELECT id, usuario, password_hash FROM usuarios WHERE usuario = ? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("s", $u);
      $stmt->execute();
      $stmt->store_result();

      if ($stmt->num_rows === 1) {
        // Leemos el resultado
        $stmt->bind_result($id, $usuario_db, $hash_db);
        $stmt->fetch();

        // Verificamos contraseña (hash bcrypt / password_hash)
        if (password_verify($p, $hash_db)) {
          // Login OK: guardamos sesión
          $_SESSION["usuario"] = $usuario_db;
          $_SESSION["usuario_id"] = (int)$id;

          // Recargamos limpio (evita re-POST al refrescar)
          header("Location: ?");
          exit;
        }
      }

      // Si llega aquí, usuario no existe o contraseña incorrecta
      $mensaje_login = "Usuario o contraseña incorrectos.";

      $stmt->close();
    } else {
      $mensaje_login = "Error preparando la consulta de login.";
    }

    $cn->close();
  }
}

/* ============================
   RENDER: Panel si hay sesión
   ============================ */
if (isset($_SESSION["usuario"])) {
?>
<!doctype html>
<html lang="es">
  <head>
    <meta charset="utf-8">
    <title>Panel de control</title>

    <!-- CSS del panel -->
    <link rel="stylesheet" href="css/panel.css">
  </head>

  <body>
    <nav>
      <a href="?tabla=reserva">Reservas</a>
      <a href="?tabla=campo">Campos</a>
      <a href="?tabla=cliente">Clientes</a>
      <a href="?tabla=lineareserva">Líneas</a>

      <a class="logout" href="?logout=1">Cerrar sesión</a>
    </nav>

    <main>
      <?php include __DIR__ . "/inc/tabla.php"; ?>
    </main>
  </body>
</html>

<?php
} else {
  // No logueado -> mostramos login
  include __DIR__ . "/inc/login.php";
}
?>

