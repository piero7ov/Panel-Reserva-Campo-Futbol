<!-- DETALLE (campo) -->
<section class="campo">
  <?php
    /*
      Conexión a la base de datos.
      Se usa para:
      - Cargar los datos del campo (nombre, descripción, precio, imagen, tipo).
      - Consultar las reservas existentes (lineareserva) y así marcar horas ocupadas.
    */
    $conexion = new mysqli("localhost", "reserva_empresa", "Reservaempresa123_", "reserva_empresa");
    $conexion->set_charset("utf8mb4");

    /*
      campoId llega por GET (?operacion=campo&campo=ID).
      Se fuerza a int para evitar inyecciones y asegurar un ID válido.
    */
    $campoId = (int)($_GET['campo'] ?? 0);

    /*
      Día seleccionado:
      - Puede llegar por GET (?dia=YYYY-MM-DD).
      - Si no llega, se usa el día de hoy.
    */
    $diaSel = isset($_GET["dia"]) ? (string)$_GET["dia"] : date("Y-m-d");

    /*
      Validación mínima de formato para evitar valores raros en la fecha.
      Si no es YYYY-MM-DD, se vuelve a usar la fecha de hoy.
    */
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $diaSel)) {
      $diaSel = date("Y-m-d");
    }

    /*
      Horario del local:
      Genera un array con horas enteras desde 09:00 hasta 21:00.
      Este array se usa para construir el <select> de horas.
    */
    $horas = [];
    for ($h = 9; $h <= 21; $h++) {
      $horas[] = sprintf("%02d:00", $h);
    }

    /*
      Devuelve un array asociativo con las horas ocupadas para un campo y un día.
      - Consulta lineareserva por campo_id + dia.
      - Marca como ocupado:
        - la hora principal
        - y si duracion=2, también la hora siguiente.
      Esto permite deshabilitar opciones en el selector.
    */
    function horasOcupadas(mysqli $conexion, int $campoId, string $diaSel): array {
      $ocup = [];

      $diaEsc = $conexion->real_escape_string($diaSel);
      $sql = "SELECT hora, duracion FROM lineareserva
              WHERE campo_id = $campoId AND dia = '$diaEsc'";

      $res = $conexion->query($sql);
      if ($res) {
        while ($r = $res->fetch_assoc()) {
          $hora = (string)$r["hora"];
          $dur  = (int)$r["duracion"];

          $ocup[$hora] = true;

          if ($dur >= 2) {
            $dt = new DateTime($diaSel . " " . $hora);
            $dt->modify("+1 hour");
            $ocup[$dt->format("H:i")] = true;
          }
        }
      }

      return $ocup;
    }

    /*
      Busca el siguiente día disponible (hasta maxDias días hacia adelante).
      - Para cada día:
        - calcula horas ocupadas
        - devuelve el primer hueco encontrado (día + hora)
      Si no encuentra huecos, retorna null.
    */
    function siguienteDisponible(mysqli $conexion, int $campoId, string $diaBase, array $horas, int $maxDias = 30): ?array {
      for ($i = 1; $i <= $maxDias; $i++) {
        $dia = date("Y-m-d", strtotime($diaBase . " +$i day"));
        $ocup = horasOcupadas($conexion, $campoId, $dia);

        foreach ($horas as $h) {
          if (!isset($ocup[$h])) {
            return ["dia" => $dia, "hora" => $h];
          }
        }
      }
      return null;
    }

    /*
      Carga el campo a mostrar.
      Se usa while por si acaso, aunque por id debería devolverte un solo registro.
    */
    $resultado = $conexion->query("SELECT * FROM campo WHERE id = ".$campoId);

    while ($fila = $resultado->fetch_assoc()) {

      /*
        Obtiene las horas ocupadas del día seleccionado.
        Con esto deshabilitamos opciones del select de horas.
      */
      $ocupadas = horasOcupadas($conexion, $campoId, $diaSel);

      /*
        Detecta si el día está completo:
        si todas las horas del rango están ocupadas, no deja reservar ese día.
      */
      $diaCompleto = true;
      foreach ($horas as $h) {
        if (!isset($ocupadas[$h])) {
          $diaCompleto = false;
          break;
        }
      }

      /*
        Si el día está completo, intentamos sugerir el siguiente día con hueco.
        Esto es una ayuda de UX para no obligar al usuario a probar fechas a ciegas.
      */
      $next = null;
      if ($diaCompleto) {
        $next = siguienteDisponible($conexion, $campoId, $diaSel, $horas, 30);
      }
  ?>

  <!--
    Este formulario manda los datos seleccionados al carrito:
    - campo (id)
    - dia
    - hora
    - duracion
    El action apunta a ?operacion=carrito para que esa vista lo añada a $_SESSION["carrito"].
  -->
  <form method="post" action="?operacion=carrito">
    <input type="hidden" name="campo" value="<?php echo (int)$fila['id']; ?>">

    <div class="izquierda">
      <img src="img/<?php echo htmlspecialchars($fila['imagen']); ?>">

      <label for="dia">Elige una fecha</label>

      <!--
        Input tipo date:
        - se muestra el día seleccionado
        - al cambiar, se recarga la página manteniendo campo y enviando la fecha por GET.
        Esto permite recalcular horas ocupadas para esa fecha sin confirmar todavía.
      -->
      <input class="control"
        id="dia"
        name="dia"
        type="date"
        value="<?php echo htmlspecialchars($diaSel); ?>"
        required
        onchange="window.location='?operacion=campo&campo=<?php echo (int)$fila['id']; ?>&dia='+this.value"
      >

      <?php if ($diaCompleto) { ?>
        <p class="notice notice--error">
          Este día está completo.
        </p>

        <?php if ($next) { ?>
          <p class="notice notice--info">
            Siguiente disponible:
            <strong><?php echo htmlspecialchars($next["dia"]); ?></strong>
            a las
            <strong><?php echo htmlspecialchars($next["hora"]); ?></strong>
          </p>

          <!-- Enlace rápido al siguiente día sugerido -->
          <a class="btn btn--secondary" href="?operacion=campo&campo=<?php echo (int)$fila['id']; ?>&dia=<?php echo htmlspecialchars($next["dia"]); ?>">
            Ver ese día
          </a>
        <?php } ?>
      <?php } ?>

      <label for="hora">Elige una hora</label>

      <!--
        Selector de horas:
        - genera todas las horas del rango
        - deshabilita las que estén ocupadas según la BD.
      -->
      <select class="control" id="hora" name="hora" required>
        <option value="">-- Selecciona --</option>

        <?php foreach ($horas as $h) {
          $ocup = isset($ocupadas[$h]);
        ?>
          <option value="<?php echo htmlspecialchars($h); ?>" <?php echo $ocup ? "disabled" : ""; ?>>
            <?php echo htmlspecialchars($h); ?><?php echo $ocup ? " (ocupado)" : ""; ?>
          </option>
        <?php } ?>

      </select>

      <button type="submit" class="btn">📌 Añadir al carrito</button>
    </div>

    <div class="derecha">
      <h3><?php echo htmlspecialchars($fila['nombre']); ?></h3>
      <p><?php echo htmlspecialchars($fila['descripcion']); ?></p>
      <p><?php echo htmlspecialchars($fila['precio_hora']); ?>€/hora</p>

      <label for="duracion">Duración (máx. 2 horas)</label>

      <!--
        Duración permitida:
        - 1 o 2 horas
        La validación real (anti doble-reserva y reglas de horario) se hace al confirmar.
      -->
      <select class="control" id="duracion" name="duracion" required>
        <option value="1">1 hora</option>
        <option value="2">2 horas</option>
      </select>
    </div>
  </form>

  <?php } ?>
</section>
