<?php
/**
 * back/inc/views/reserva_list.php
 * Listado de reservas
 */

  $q = trim((string)($_GET["q"] ?? ""));
  $f_fecha = trim((string)($_GET["fecha"] ?? ""));
  $f_campo = to_int($_GET["campo_id"] ?? 0);

  $sort = (string)($_GET["sort"] ?? "reserva");
  $dir  = strtolower((string)($_GET["dir"] ?? "desc")) === "asc" ? "asc" : "desc";

  $SORT_MAP = [
    "reserva"   => "r.id",
    "fecha"     => "r.fecha",
    "cliente"   => "c.nombre",
    "email"     => "c.email",
    "telefono"  => "c.telefono",
    "dia"       => "lr.dia",
    "hora"      => "lr.hora",
    "duracion"  => "lr.duracion",
    "campo"     => "ca.nombre",
    "tipo"      => "ca.tipo",
    "precio"    => "ca.precio_hora",
  ];
  if (!isset($SORT_MAP[$sort])) $sort = "reserva";

  $campos = getCampos($cn);

  echo "<div style='margin:0 0 10px; display:flex; gap:10px; align-items:center;'>";
  echo "<a class='btn primary' href='?tabla=reserva&accion=nueva'>+ Nueva reserva</a>";
  echo "</div>";

  echo "<form class='filters' method='GET' action='?'>";
  echo "<input type='hidden' name='tabla' value='reserva'>";

  echo "<div class='field grow'><label>Buscar cliente</label><input type='text' name='q' value='" . e($q) . "' placeholder='Nombre, email o teléfono'></div>";
  echo "<div class='field'><label>Fecha</label><input type='date' name='fecha' value='" . e($f_fecha) . "'></div>";

  echo "<div class='field grow'><label>Campo</label><select name='campo_id'>";
  echo "<option value='0'>Todos</option>";
  foreach ($campos as $ca) {
    $sel = ((int)$ca["id"] === $f_campo) ? " selected" : "";
    $txt = $ca["nombre"] . " · " . $ca["tipo"];
    echo "<option value='" . e($ca["id"]) . "'" . $sel . ">" . e($txt) . "</option>";
  }
  echo "</select></div>";

  echo "<div class='actions'>";
  echo "<button class='btn primary' type='submit'>Aplicar</button>";
  echo "<a class='btn' href='?tabla=reserva'>Limpiar</a>";
  echo "</div>";

  echo "</form>";

  $where = [];
  $types = "";
  $params = [];

  if ($q !== "") {
    $like = "%" . $q . "%";
    $where[] = "(c.nombre LIKE ? OR c.apellidos LIKE ? OR c.email LIKE ? OR c.telefono LIKE ?)";
    $types .= "ssss";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
  }

  if ($f_fecha !== "") {
    $where[] = "(r.fecha LIKE ? OR lr.dia = ?)";
    $types .= "ss";
    $params[] = $f_fecha . "%";
    $params[] = $f_fecha;
  }

  if ($f_campo > 0) {
    $where[] = "(ca.id = ?)";
    $types .= "i";
    $params[] = $f_campo;
  }

  $selectEstado = $HAS_ESTADO ? "r.estado AS estado," : "'pendiente' AS estado,";

  $sql = "
    SELECT
      r.id        AS reserva_id,
      r.fecha     AS fecha,
      {$selectEstado}
      c.nombre    AS cliente_nombre,
      c.apellidos AS cliente_apellidos,
      c.email     AS cliente_email,
      c.telefono  AS cliente_telefono,

      lr.id       AS linea_id,
      lr.dia      AS dia,
      lr.hora     AS hora,
      lr.duracion AS duracion,

      ca.nombre   AS campo_nombre,
      ca.tipo     AS campo_tipo,
      ca.precio_hora AS campo_precio_hora
    FROM reserva r
    LEFT JOIN cliente c ON c.id = r.cliente_id
    LEFT JOIN lineareserva lr ON lr.reserva_id = r.id
    LEFT JOIN campo ca ON ca.id = lr.campo_id
  ";

  if (count($where) > 0) $sql .= " WHERE " . implode(" AND ", $where);
  $sql .= " ORDER BY " . $SORT_MAP[$sort] . " " . $dir . ", r.id DESC, lr.id ASC";

  $st = $cn->prepare($sql);
  if ($types !== "") $st->bind_param($types, ...$params);
  $st->execute();
  $res = $st->get_result();

  $base = ["tabla" => "reserva", "q" => $q, "fecha" => $f_fecha, "campo_id" => $f_campo];
  $makeSortUrl = function(string $col) use ($base, $sort, $dir): string {
    $nextDir = ($sort === $col && $dir === "asc") ? "desc" : "asc";
    $qs = $base;
    $qs["sort"] = $col;
    $qs["dir"]  = $nextDir;
    return "?" . http_build_query($qs);
  };
  $arrow = function(string $col) use ($sort, $dir): string {
    if ($sort !== $col) return "";
    return $dir === "asc" ? " ▲" : " ▼";
  };

  $return_url = "?" . http_build_query(array_merge($base, ["sort" => $sort, "dir" => $dir]));

  echo "<table>";
  echo "<tr>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("reserva")) . "'>Reserva" . e($arrow("reserva")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("fecha")) . "'>Fecha" . e($arrow("fecha")) . "</a></th>";
  echo "<th>Estado</th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("cliente")) . "'>Cliente" . e($arrow("cliente")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("email")) . "'>Email" . e($arrow("email")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("telefono")) . "'>Teléfono" . e($arrow("telefono")) . "</a></th>";
  echo "<th>Línea</th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("dia")) . "'>Día" . e($arrow("dia")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("hora")) . "'>Hora" . e($arrow("hora")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("duracion")) . "'>Duración" . e($arrow("duracion")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("campo")) . "'>Campo" . e($arrow("campo")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("tipo")) . "'>Tipo" . e($arrow("tipo")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("precio")) . "'>Precio/h" . e($arrow("precio")) . "</a></th>";
  echo "<th>Acciones</th>";
  echo "</tr>";

  if ($res && $res->num_rows > 0) {
    while ($r = $res->fetch_assoc()) {

      $cliente = trim((string)$r["cliente_nombre"] . " " . (string)$r["cliente_apellidos"]);
      if ($cliente === "") $cliente = "-";

      $linea_id   = (int)($r["linea_id"] ?? 0);
      $reserva_id = (int)($r["reserva_id"] ?? 0);

      $estado = strtolower(trim((string)($r["estado"] ?? "pendiente")));
      if ($estado === "") $estado = "pendiente";

      echo "<tr>";
      echo "<td>" . e($r["reserva_id"]) . "</td>";
      echo "<td>" . e($r["fecha"]) . "</td>";
      echo "<td>" . estado_badge($estado) . "</td>";
      echo "<td>" . e($cliente) . "</td>";
      echo "<td>" . e($r["cliente_email"]) . "</td>";
      echo "<td>" . e($r["cliente_telefono"]) . "</td>";

      echo "<td>" . e($r["linea_id"]) . "</td>";
      echo "<td>" . e($r["dia"]) . "</td>";
      echo "<td>" . e($r["hora"]) . "</td>";
      echo "<td>" . e($r["duracion"]) . "</td>";

      echo "<td>" . e($r["campo_nombre"]) . "</td>";
      echo "<td>" . e($r["campo_tipo"]) . "</td>";
      echo "<td>" . e($r["campo_precio_hora"]) . "</td>";

      echo "<td style='white-space:nowrap; display:flex; gap:8px; flex-wrap:wrap;'>";

      if ($linea_id > 0) {
        echo "<a class='btn sm' href='?tabla=reserva&accion=ver&linea_id=" . e($linea_id) . "'>Ver</a>";
        echo "<a class='btn sm' href='?tabla=reserva&accion=editar&linea_id=" . e($linea_id) . "'>Editar</a>";
      } else {
        echo "<span style='opacity:.8;'>-</span>";
      }

      if ($HAS_ESTADO && $reserva_id > 0) {

        if ($estado === "pendiente") {
          echo "<form method='POST' action='?tabla=reserva' style='display:inline; margin:0;'>";
          echo "<input type='hidden' name='accion_reserva' value='confirmar'>";
          echo "<input type='hidden' name='reserva_id' value='" . e($reserva_id) . "'>";
          echo "<input type='hidden' name='return_url' value='" . e($return_url) . "'>";
          echo "<button class='btn sm primary' type='submit' onclick='return confirm(\"¿Confirmar y enviar correo al cliente?\");'>Confirmar</button>";
          echo "</form>";
        }

        if ($estado !== "cancelada") {
          echo "<form method='POST' action='?tabla=reserva' style='display:inline; margin:0;'>";
          echo "<input type='hidden' name='accion_reserva' value='cancelar'>";
          echo "<input type='hidden' name='reserva_id' value='" . e($reserva_id) . "'>";
          echo "<input type='hidden' name='return_url' value='" . e($return_url) . "'>";
          echo "<button class='btn sm warn' type='submit' onclick='return confirm(\"¿Cancelar esta reserva?\");'>Cancelar</button>";
          echo "</form>";
        }

        if ($estado === "cancelada") {
          echo "<form method='POST' action='?tabla=reserva' style='display:inline; margin:0;'>";
          echo "<input type='hidden' name='accion_reserva' value='eliminar'>";
          echo "<input type='hidden' name='reserva_id' value='" . e($reserva_id) . "'>";
          echo "<input type='hidden' name='return_url' value='" . e($return_url) . "'>";
          echo "<button class='btn sm danger' type='submit' onclick='return confirm(\"Esto eliminará definitivamente la reserva y sus líneas. ¿Continuar?\");'>Eliminar</button>";
          echo "</form>";
        }
      }

      echo "</td>";
      echo "</tr>";
    }
  } else {
    echo "<tr><td colspan='14'>No hay registros.</td></tr>";
  }

  echo "</table>";

  $st->close();
