<!doctype html>
<html lang="es">
  <head>
    <meta charset="utf-8">
    <title>Login Aplicación</title>
    <link rel="stylesheet" href="css/login.css">
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
