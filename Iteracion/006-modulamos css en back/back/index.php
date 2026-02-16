<?php
  session_start();

  // ============================
  // LOGOUT (cerrar sesión)
  // ============================
  if (isset($_GET["logout"])) {
    unset($_SESSION["usuario"]);
    session_destroy();
    header("Location: ?");
    exit;
  }

  // ============================
  // LOGIN
  // ============================
  if (isset($_POST["usuario"])) {
    if ($_POST["usuario"] == "piero7ov" && $_POST["contrasena"] == "piero7ov") {
      $_SESSION["usuario"] = "piero7ov";
      header("Location: ?"); // recarga limpio
      exit;
    }
  }
?>

<?php
  // ============================
  // PANEL (si hay sesión)
  // ============================
  if (isset($_SESSION["usuario"]) && $_SESSION["usuario"] == "piero7ov") {
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
      <a href="?tabla=campo">Campos</a>
      <a href="?tabla=cliente">Clientes</a>
      <a href="?tabla=reserva">Reservas</a>

      <a class="logout" href="?logout=1">Cerrar sesión</a>
    </nav>

    <main>
      <div class="topbar">
        <h2>Vista de tabla</h2>
      </div>

      <?php include __DIR__ . "/inc/tabla.php"; ?>

    </main>

  </body>
</html>

<?php
  } else {
    // Si NO está logueado, mostramos el login modular
    include __DIR__ . "/inc/login.php";
  }
?>
