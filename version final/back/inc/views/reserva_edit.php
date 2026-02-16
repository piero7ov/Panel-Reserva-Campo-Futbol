<?php
/**
 * back/inc/views/reserva_edit.php
 * Formulario para editar reserva
 */

  $linea_id = to_int($_GET["linea_id"] ?? 0);

  $selectEstado = $HAS_ESTADO ? "r.estado AS estado," : "'pendiente' AS estado,";

  $sql = "
    SELECT
      r.id        AS reserva_id,
      r.fecha     AS fecha,
      {$selectEstado}
      r.cliente_id AS cliente_id,

      lr.id       AS linea_id,
      lr.dia      AS dia,
      lr.hora     AS hora,
      lr.duracion AS duracion,
      lr.campo_id AS campo_id
    FROM lineareserva lr
    LEFT JOIN reserva r ON r.id = lr.reserva_id
    WHERE lr.id = ?
    LIMIT 1
  ";
  $st = $cn->prepare($sql);
  $st->bind_param("i", $linea_id);
  $st->execute();
  $res = $st->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $st->close();

  echo "<div style='margin:0 0 12px; display:flex; gap:10px; align-items:center;'>";
  echo "<a class='btn' href='?tabla=reserva'>← Volver</a>";
  echo "</div>";

  if (!$row) { notice_html("No encontrado.", "warn"); return; }

  $clientes = getClientes($cn);
  $campos   = getCampos($cn);

  $fecha_val = (string)$row["fecha"];
  $fecha_val = str_replace(" ", "T", $fecha_val);
  $fecha_val = preg_replace('/:\d{2}$/', '', $fecha_val);

  $estado  = strtolower(trim((string)($row["estado"] ?? "pendiente")));
  if ($estado === "") $estado = "pendiente";

  notice_html("<strong>Estado:</strong> " . estado_badge($estado), "info");

  echo "<form method='POST' action='?tabla=reserva&accion=editar&linea_id=" . e($linea_id) . "' class='filters'>";
  echo "<input type='hidden' name='guardar_edicion' value='1'>";
  echo "<input type='hidden' name='linea_id' value='" . e($row["linea_id"]) . "'>";
  echo "<input type='hidden' name='reserva_id' value='" . e($row["reserva_id"]) . "'>";

  echo "<div class='field grow'><label>Cliente</label><select name='cliente_id' required>";
  foreach ($clientes as $c) {
    $selected = ((int)$c["id"] === (int)$row["cliente_id"]) ? " selected" : "";
    $label = trim($c["nombre"] . " " . $c["apellidos"]);
    $extra = trim((string)$c["email"]);
    $txt = "#" . $c["id"] . " - " . $label;
    if ($extra !== "") $txt .= " (" . $extra . ")";
    echo "<option value='" . e($c["id"]) . "'" . $selected . ">" . e($txt) . "</option>";
  }
  echo "</select></div>";

  echo "<div class='field'><label>Fecha (reserva)</label><input type='datetime-local' name='fecha' value='" . e($fecha_val) . "' required></div>";

  echo "<div class='field grow'><label>Campo</label><select name='campo_id' required>";
  foreach ($campos as $ca) {
    $selected = ((int)$ca["id"] === (int)$row["campo_id"]) ? " selected" : "";
    $txt = "#" . $ca["id"] . " - " . $ca["nombre"] . " · " . $ca["tipo"] . " · " . $ca["precio_hora"];
    echo "<option value='" . e($ca["id"]) . "'" . $selected . ">" . e($txt) . "</option>";
  }
  echo "</select></div>";

  echo "<div class='field'><label>Día</label><input type='date' name='dia' value='" . e($row["dia"]) . "' required></div>";
  echo "<div class='field'><label>Hora</label><input type='time' name='hora' value='" . e($row["hora"]) . "' required></div>";
  echo "<div class='field'><label>Duración</label><input type='text' name='duracion' value='" . e($row["duracion"]) . "' required></div>";

  echo "<div class='actions'>";
  echo "<button class='btn primary' type='submit'>Guardar</button>";
  echo "<a class='btn' href='?tabla=reserva'>Cancelar</a>";
  echo "</div>";

  echo "</form>";
