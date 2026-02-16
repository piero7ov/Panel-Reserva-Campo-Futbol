<?php
/**
 * back/inc/views/reserva_detail.php
 * Vista detalle de reserva
 */

  $linea_id = to_int($_GET["linea_id"] ?? 0);

  $selectEstado = $HAS_ESTADO ? "r.estado AS estado," : "'pendiente' AS estado,";

  $sql = "
    SELECT
      r.id        AS reserva_id,
      r.fecha     AS fecha,
      {$selectEstado}
      c.id        AS cliente_id,
      c.nombre    AS cliente_nombre,
      c.apellidos AS cliente_apellidos,
      c.email     AS cliente_email,
      c.telefono  AS cliente_telefono,

      lr.id       AS linea_id,
      lr.dia      AS dia,
      lr.hora     AS hora,
      lr.duracion AS duracion,

      ca.id       AS campo_id,
      ca.nombre   AS campo_nombre,
      ca.tipo     AS campo_tipo,
      ca.precio_hora AS campo_precio_hora
    FROM lineareserva lr
    LEFT JOIN reserva r ON r.id = lr.reserva_id
    LEFT JOIN cliente c ON c.id = r.cliente_id
    LEFT JOIN campo ca  ON ca.id = lr.campo_id
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
  if ($row) echo "<a class='btn primary' href='?tabla=reserva&accion=editar&linea_id=" . e($linea_id) . "'>Editar</a>";
  echo "</div>";

  if (!$row) { notice_html("No encontrado.", "warn"); return; }

  $cliente = trim((string)$row["cliente_nombre"] . " " . (string)$row["cliente_apellidos"]);
  $estado  = strtolower(trim((string)($row["estado"] ?? "pendiente")));
  if ($estado === "") $estado = "pendiente";

  echo "<table>";
  echo "<tr><th>Estado</th><td>" . estado_badge($estado) . "</td></tr>";
  echo "<tr><th>Campo</th><td>" . e($row["campo_nombre"]) . " (" . e($row["campo_tipo"]) . ")</td></tr>";
  echo "<tr><th>Precio/h</th><td>" . e($row["campo_precio_hora"]) . "</td></tr>";
  echo "<tr><th>Reserva</th><td>" . e($row["reserva_id"]) . "</td></tr>";
  echo "<tr><th>Fecha</th><td>" . e($row["fecha"]) . "</td></tr>";
  echo "<tr><th>Cliente</th><td>" . e($cliente) . "</td></tr>";
  echo "<tr><th>Email</th><td>" . e($row["cliente_email"]) . "</td></tr>";
  echo "<tr><th>Teléfono</th><td>" . e($row["cliente_telefono"]) . "</td></tr>";
  echo "<tr><th>Línea</th><td>" . e($row["linea_id"]) . "</td></tr>";
  echo "<tr><th>Día</th><td>" . e($row["dia"]) . "</td></tr>";
  echo "<tr><th>Hora</th><td>" . e($row["hora"]) . "</td></tr>";
  echo "<tr><th>Duración</th><td>" . e($row["duracion"]) . "</td></tr>";
  echo "</table>";
