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
  /* =========================
     PANEL
     ========================= */

  :root{
    --bg0: #04110a;          /* fondo oscuro */
    --bg1: #071a12;          /* fondo verde profundo */
    --surface: rgba(255,255,255,.06);
    --surface2: rgba(255,255,255,.10);
    --border: rgba(255,255,255,.12);

    --text: #e8fff2;
    --muted: rgba(232,255,242,.75);

    --accent: #22c55e;       /* verde vivo */
    --accent2: #16a34a;      /* verde más oscuro */
    --danger: #ef4444;       /* para logout */
  }

  html,body{height:100%;}
  body{
    margin:0;
    display:flex;
    font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
    color: var(--text);

    /* Fondo tipo “estadio” */
    background:
      radial-gradient(1200px 800px at 20% 10%, rgba(34,197,94,.25), transparent 60%),
      radial-gradient(900px 600px at 90% 20%, rgba(22,163,74,.18), transparent 55%),
      linear-gradient(180deg, var(--bg1), var(--bg0));
  }

  /* ===== Sidebar ===== */
  nav{
    width: 260px;
    padding: 18px;
    display:flex;
    flex-direction:column;
    gap: 10px;

    background: linear-gradient(180deg, rgba(6,78,59,.55), rgba(4,17,10,.70));
    border-right: 1px solid rgba(34,197,94,.25);
    box-shadow: 0 18px 50px rgba(0,0,0,.35);
    backdrop-filter: blur(10px);
  }

  nav a{
    display:block;
    padding: 10px 12px;
    border-radius: 14px;
    text-decoration:none;
    color: var(--text);

    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.10);

    transition: transform .15s ease, background .15s ease, border-color .15s ease;
  }

  nav a:hover{
    background: rgba(34,197,94,.12);
    border-color: rgba(34,197,94,.35);
    transform: translateY(-1px);
  }

  /* Logout (usa tu class="logout") */
  nav a.logout{
    margin-top: auto;
    background: rgba(239,68,68,.10);
    border-color: rgba(239,68,68,.25);
    text-align: center;
  }
  nav a.logout:hover{
    background: rgba(239,68,68,.16);
    border-color: rgba(239,68,68,.40);
  }

  /* ===== Main ===== */
  main{
    flex:1;
    padding: 22px;
    overflow:auto;
  }

  .topbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap: 12px;
    margin-bottom: 14px;
  }

  .topbar h2{
    margin:0;
    font-size: 18px;
    letter-spacing: .2px;
  }

  /* ===== Tabla ===== */
  main table{
    width:100%;
    border-collapse: collapse;
    overflow:hidden;
    border-radius: 16px;

    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.12);
    box-shadow: 0 20px 60px rgba(0,0,0,.35);
  }

  main table th{
    position: sticky;
    top: 0;
    z-index: 1;

    text-align:left;
    font-size: 12px;
    letter-spacing: .5px;
    text-transform: uppercase;

    padding: 12px;
    background: linear-gradient(180deg, rgba(34,197,94,.18), rgba(255,255,255,.06));
    border-bottom: 1px solid rgba(255,255,255,.14);
    backdrop-filter: blur(10px);
  }

  main table td{
    padding: 12px;
    border-bottom: 1px solid rgba(255,255,255,.08);
    color: var(--text);
    font-size: 14px;

    /* Para que textos largos no rompan el layout */
    max-width: 360px;
    word-break: break-word;
  }

  main table tr:nth-child(even) td{
    background: rgba(255,255,255,.02);
  }

  main table tr:hover td{
    background: rgba(34,197,94,.08);
  }

  /* ===== Responsive ===== */
  @media (max-width: 900px){
    body{flex-direction:column;}
    nav{
      width:auto;
      flex-direction:row;
      gap: 8px;
      overflow-x:auto;
      border-right:0;
      border-bottom: 1px solid rgba(34,197,94,.25);
    }
    nav a{white-space: nowrap;}
    nav a.logout{margin-top:0; margin-left:auto;}
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
