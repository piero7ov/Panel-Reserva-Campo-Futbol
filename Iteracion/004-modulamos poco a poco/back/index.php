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

    <style>
      html,body{padding:0px;margin:0px;width:100%;height:100%;}
      body{display:flex;font-family:system-ui, sans-serif;background:#0b1220;color:#e5e7eb;}

      /* NAV */
      nav{
        background: rgba(255,255,255,.06);
        flex:1;
        display:flex;
        flex-direction:column;
        padding:20px;
        gap:20px;
        border-right:1px solid rgba(255,255,255,.10);
      }

      nav a{
        background: rgba(255,255,255,.08);
        padding:10px;
        color:inherit;
        text-decoration:none;
        border-radius:12px;
        border:1px solid rgba(255,255,255,.12);
      }

      /* MAIN */
      main{
        background: transparent;
        flex:4;
        padding:20px;
        overflow:auto;
      }

      main h2{
        margin:0 0 14px;
        font-size:18px;
      }

      /* TABLA */
      main table{
        width:100%;
        border:1px solid rgba(255,255,255,.12);
        border-collapse:collapse;
        background: rgba(255,255,255,.04);
        border-radius:14px;
        overflow:hidden;
      }

      main table th{
        background: rgba(255,255,255,.10);
        position: sticky;
        top: 0;
      }

      main table th,
      main table td{
        padding:10px;
        border-bottom:1px solid rgba(255,255,255,.08);
        text-align:left;
        vertical-align:top;
      }

      .topbar{
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:10px;
        margin-bottom:14px;
      }

      .logout{
        display:inline-block;
        padding:10px 12px;
        border-radius:12px;
        border:1px solid rgba(255,255,255,.12);
        background: rgba(255,255,255,.08);
        color:inherit;
        text-decoration:none;
      }
    </style>
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
