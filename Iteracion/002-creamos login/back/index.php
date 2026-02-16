<?php
  session_start();
  // Si entra por ?logout=1:
  // - borra la sesión
  // - redirige al mismo archivo sin parámetros (vuelve al login)
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
      header("Location: ?"); // recarga limpio (evita re-POST al refrescar)
      exit;
    }
  }
?>

<?php
  // Si hay sesión válida, mostramos el panel
  if (isset($_SESSION["usuario"]) && $_SESSION["usuario"] == "piero7ov") {
?>
<!doctype html>
<html lang="es">
  <head>
    <meta charset="utf-8">
    <title>Panel de control</title>
    <style>
      html,body{padding:0;margin:0;width:100%;height:100%;}
      body{
        font-family: system-ui, sans-serif;
        display:flex;
        justify-content:center;
        align-items:center;
        background:#0b1220;
        color:#e5e7eb;
      }
      .box{
        width:min(900px, 92vw);
        padding:24px;
        border-radius:18px;
        border:1px solid rgba(255,255,255,.12);
        background:rgba(255,255,255,.06);
        backdrop-filter: blur(10px);
      }
      a{color:#93c5fd;text-decoration:none;}
    </style>
  </head>
  <body>
    <div class="box">
      Te voy a mostrar el panel de control<br><br>
      <a href="?logout=1">Cerrar sesión</a>
    </div>
  </body>
</html>

<?php
  } else {
?>

<!doctype html>
<html lang="es">
  <head>
    <meta charset="utf-8">
    <title>Login Aplicación</title>

    <style>
      @font-face { /* Carga fuente para títulos */
        font-family: inicio;
        src: url(fuentes/LEMONMILK-Bold-inicio.otf);
      }

      @font-face { /* Carga fuente ligera para textos */
        font-family: parrafo;
        src: url(fuentes/LEMONMILK-Light.otf);
      }

      @font-face { /* Carga fuente regular para subtítulos/botones */
        font-family: subtitulo;
        src: url(fuentes/LEMONMILK-Regular.otf);
      }

      html { height: 100%; }

      body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        background: url(img/fondo.png) center/cover no-repeat;
      }

      main {
        width: 500px;
        height: 590px;
        background: rgba(255, 255, 255, 0.2);
        box-sizing: border-box;
        padding: 30px;
        backdrop-filter: blur(15px);
        text-align: center;
        color: #d4eded;
        border: 3px solid rgba(202, 253, 212, 0.92);
        border-radius: 30px;
        padding-bottom: 10px;
        font-family: parrafo;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
        padding-top: 5px;
        animation: aparecer 1.3s ease forwards;
        opacity: 0;
      }

      /* CAMPOS */
      input[type=text],
      input[type=password] {
        width: 100%;
        margin: 15px 0px;
        padding: 15px 0px;
        border: none;
        background: rgba(0, 0, 0, 0);
        border-bottom: 1px solid rgba(202, 253, 212, 0.92);
        outline: none;
        font-weight: bold;
        font-size: 20px;
        color: darkslategrey;
      }

      input::placeholder {
        color: #d4eded;
        font-family: parrafo;
      }

      h1 {
        font-size: 40px;
        font-family: inicio, cursive;
        margin: 0px;
        color: darkslategrey;
        text-transform: uppercase;
        text-shadow: 0 3px 6px rgba(0, 0, 0, 0.2);
      }

      /* BOTÓN */
      input[type=submit] {
        margin: 15px 0px;
        padding: 15px 0px;
        background: rgba(202, 253, 212, 0.92);
        color: darkslategrey;
        width: 300px;
        border-radius: 50px;
        font-family: subtitulo;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        font-size: 20px;
        border: none;
        font-weight: 600;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
      }

      input[type=submit]:hover {
        transform: scale(1.05);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.5);
      }

      /* Iconos */
      input[name=usuario]{
        background: url(img/email.png) no-repeat right center;
        background-size: 25px;
      }

      input[name=contrasena]{
        background: url(img/password.png) no-repeat right center;
        background-size: 25px;
      }

      .recordar-container {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        margin-top: 40px;
        user-select: none;
      }

      #olvidar { margin-left: 30px; }

      p { margin-bottom: 5px; }

      a {
        color: #d4eded;
        text-decoration: none;
        transition: color 0.3s ease;
      }

      a:hover { color: darkslategrey; }

      img {
        width: 110px;
        display: block;
        margin: 0px auto 0px auto;
        filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.4));
      }

      #mensaje-login {
        margin-top: 5px;
        font-size: 12px;
        min-height: 18px;
        font-family: parrafo;
      }

      input[type=text]:focus,
      input[type=password]:focus {
        border-bottom: 2px solid #aef9d8;
        transition: border-bottom 0.3s ease;
      }

      @keyframes aparecer {
        from { transform: translateY(50px); opacity: 0; }
        to   { transform: translateY(0); opacity: 1; }
      }
    </style>
  </head>

  <body>
    <main>
      <form method="POST" action="?">
        <img src="img/logo.png" alt="Logo de marca">

        <h1>Iniciar sesión</h1>

        <input type="text" name="usuario" placeholder="Usuario">
        <input type="password" name="contrasena" placeholder="Contraseña">

        <div class="recordar-container">
          <input type="checkbox" id="recordar">
          <label for="recordar">Recordar</label>
        </div>

        <a href="#" id="olvidar">Olvidé la contraseña</a>

        <input type="submit" value="Acceder">

        <p id="mensaje-login"></p>

        <p>No tengo cuenta</p>
        <a href="crear.html" id="crear">Crear una</a>
      </form>
    </main>
  </body>
</html>

<?php
  }
?>
