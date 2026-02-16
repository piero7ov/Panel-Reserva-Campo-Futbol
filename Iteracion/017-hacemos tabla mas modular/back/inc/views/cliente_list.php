<?php
/**
 * back/inc/views/cliente_list.php
 * Listado de clientes
 */

  $q = trim((string)($_GET["q"] ?? ""));
  $sort = (string)($_GET["sort"] ?? "id");
  $dir  = strtolower((string)($_GET["dir"] ?? "desc")) === "asc" ? "asc" : "desc";

  $SORT_MAP = [
    "id"        => "c.id",
    "nombre"    => "c.nombre",
    "apellidos" => "c.apellidos",
    "email"     => "c.email",
    "telefono"  => "c.telefono",
  ];
  if (!isset($SORT_MAP[$sort])) $sort = "id";

  echo "<div style='margin:0 0 10px; display:flex; gap:10px; align-items:center;'>";
  echo "<a class='btn primary' href='?tabla=cliente&accion=nuevo'>+ Nuevo cliente</a>";
  echo "</div>";

  $where = [];
  $types = "";
  $params = [];

  if ($q !== "") {
    $like = "%" . $q . "%";
    $where[] = "(c.nombre LIKE ? OR c.apellidos LIKE ? OR c.email LIKE ? OR c.telefono LIKE ?)";
    $types .= "ssss";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
  }

  $sql = "SELECT c.id, c.nombre, c.apellidos, c.email, c.telefono FROM cliente c";
  if (count($where) > 0) $sql .= " WHERE " . implode(" AND ", $where);
  $sql .= " ORDER BY " . $SORT_MAP[$sort] . " " . $dir;

  echo "<form class='filters' method='GET' action='?'>";
  echo "<input type='hidden' name='tabla' value='cliente'>";

  echo "<div class='field grow'><label>Buscar cliente</label><input type='text' name='q' value='" . e($q) . "' placeholder='Nombre, email o teléfono'></div>";

  echo "<div class='actions'>";
  echo "<button class='btn primary' type='submit'>Aplicar</button>";
  echo "<a class='btn' href='?tabla=cliente'>Limpiar</a>";
  echo "</div>";

  echo "</form>";

  $st = $cn->prepare($sql);
  if ($types !== "") $st->bind_param($types, ...$params);
  $st->execute();
  $res = $st->get_result();

  $base = ["tabla" => "cliente", "q" => $q];
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

  echo "<table>";
  echo "<tr>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("id")) . "'>ID" . e($arrow("id")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("nombre")) . "'>Nombre" . e($arrow("nombre")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("apellidos")) . "'>Apellidos" . e($arrow("apellidos")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("email")) . "'>Email" . e($arrow("email")) . "</a></th>";
  echo "<th><a class='sort' href='" . e($makeSortUrl("telefono")) . "'>Teléfono" . e($arrow("telefono")) . "</a></th>";
  echo "</tr>";

  if ($res && $res->num_rows > 0) {
    while ($r = $res->fetch_assoc()) {
      echo "<tr>";
      echo "<td>" . e($r["id"]) . "</td>";
      echo "<td>" . e($r["nombre"]) . "</td>";
      echo "<td>" . e($r["apellidos"]) . "</td>";
      echo "<td>" . e($r["email"]) . "</td>";
      echo "<td>" . e($r["telefono"]) . "</td>";
      echo "</tr>";
    }
  } else {
    echo "<tr><td colspan='5'>No hay registros.</td></tr>";
  }

  echo "</table>";
  $st->close();
