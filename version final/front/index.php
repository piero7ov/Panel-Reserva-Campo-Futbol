<?php
/* 
  Inicia la sesión para poder guardar el carrito (reservas) en $_SESSION["carrito"].
  Sin esto, el carrito se perdería al cambiar de página.
*/
session_start();

/*
  Calcula cuántos ítems hay en el carrito para mostrarlo en el header.
  Si aún no existe la sesión "carrito", el contador será 0.
*/
$carritoCount = isset($_SESSION["carrito"]) ? count($_SESSION["carrito"]) : 0;
?>
<!doctype html>
<html lang="es">

<head>
  <title>Reserva de campos de futbol</title>
  <meta charset="utf-8">

  <!-- Hoja de estilos principal del sitio -->
  <link rel="stylesheet" href="css/estilo.css">
</head>

<body>

  <header>
    <!-- 
      Enlace transparente sobre todo el hero.
      Permite volver al catálogo (URL base "?") desde cualquier pantalla.
    -->
    <a class="hero-link" href="?" aria-label="Volver al catálogo"></a>

    <div class="hero-content">
      <h1>Reserva de campos de futbol</h1>
      <h2>Reserva en 1 minuto y juega hoy con tus amigos</h2>
    </div>

    <!-- Acceso directo al carrito mostrando el número de reservas en sesión -->
    <a class="cart-link" href="?operacion=carrito">🛒 Carrito (<?php echo $carritoCount; ?>)</a>
  </header>

  <main>
    <?php
      /*
        Router simple por querystring:
        - Si existe ?operacion=..., cargamos la vista correspondiente.
        - Si no existe, mostramos el catálogo.
        
        Esto mantiene un único punto de entrada (index.php) y separa las vistas en /inc.
      */
      if (isset($_GET['operacion'])) {

        if ($_GET['operacion'] == "campo") {
          // Vista de detalle del campo (selección de fecha/hora/duración y añadir al carrito)
          include "inc/campo.php";
        } else if ($_GET['operacion'] == "carrito") {
          // Vista del carrito: lista reservas en sesión + formulario de datos del cliente
          include "inc/carrito.php";
        } else if ($_GET['operacion'] == "finalizacion") {
          // Vista final: valida y guarda en base de datos (cliente, reserva y líneas)
          include "inc/finalizacion.php";
        }

      } else {
        // Vista por defecto: catálogo de campos disponible
        include "inc/catalogo.php";
      }
    ?>
  </main>

  <footer>
    (c) PieroDev 2026
  </footer>

</body>
</html>

