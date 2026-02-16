<?php
/**
 * inc/tabla.php — Visor de tablas (JOIN + Filtros + Orden + Acciones + Detalle)
 * ----------------------------------------------------------------------------
 * - "reserva": listado con JOIN + búsqueda/filtros + orden por click
 *   + Acciones por fila (Ver) + Vista detalle (?tabla=reserva&ver=ID)
 * - "lineareserva": listado con JOIN + búsqueda/filtros + orden por click
 * - "cliente": búsqueda + orden por click
 * - "campo" y otras: vista genérica SELECT *
 */

/* ============================
   1) Tablas permitidas (menú del panel)
   ============================ */
$TABLAS_PERMITIDAS = [
  "reserva"      => "Reservas",
  "lineareserva" => "Líneas de reserva",
  "campo"        => "Campos",
  "cliente"      => "Clientes",
];

/* Tabla actual (por defecto: reserva) */
$tabla = $_GET["tabla"] ?? "reserva";
if (!isset($TABLAS_PERMITIDAS[$tabla])) {
  $tabla = "reserva";
}

/* ============================
   2) Conexión usando la config del index.php
   (si alguien ejecuta este include sin index.php, ponemos fallback)
   ============================ */
if (!isset($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME)) {
  $DB_HOST = "localhost";
  $DB_USER = "reserva_empresa";
  $DB_PASS = "Reservaempresa123_";
  $DB_NAME = "reserva_empresa";
}

$conexion = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$conexion->set_charset("utf8mb4");

/* ============================
   3) Helpers
   ============================ */

/** Escapa texto para HTML */
function e($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

/**
 * Convierte un nombre de columna técnico a una etiqueta más humana
 * (solo para la vista genérica).
 */
function labelBonito(string $col): string {
  $map = [
    "id"          => "ID",
    "nombre"      => "Nombre",
    "apellidos"   => "Apellidos",
    "telefono"    => "Teléfono",
    "email"       => "Email",
    "fecha"       => "Fecha",
    "dia"         => "Día",
    "hora"        => "Hora",
    "duracion"    => "Duración",
    "tipo"        => "Tipo",
    "descripcion" => "Descripción",
    "precio_hora" => "Precio/h",
    "imagen"      => "Imagen",

    // FKs reales del modelo
    "cliente_id"  => "Cliente (ID)",
    "reserva_id"  => "Reserva (ID)",
    "campo_id"    => "Campo (ID)",
  ];

  if (isset($map[$col])) return $map[$col];

  $s = str_replace(["_", "-"], " ", $col);
  $s = mb_convert_case($s, MB_CASE_TITLE, "UTF-8");
  $s = preg_replace('/\bId\b/u', 'ID', $s);

  return $s;
}

/**
 * Construye una URL manteniendo parámetros actuales, aplicando overrides.
 * - Si override trae null, elimina el parámetro.
 */
function urlCon(array $override): string {
  $params = $_GET;

  foreach ($override as $k => $v) {
    if ($v === null) {
      unset($params[$k]);
    } else {
      $params[$k] = $v;
    }
  }

  foreach ($params as $k => $v) {
    if ($v === "" || $v === false) unset($params[$k]);
  }

  $qs = http_build_query($params);
  return $qs ? ("?".$qs) : "?";
}

/**
 * bind_param dinámico (por referencia) para mysqli.
 */
function stmtBind(mysqli_stmt $stmt, string $types, array $params): void {
  $bind = [];
  $bind[] = $types;
  foreach ($params as $i => $val) {
    $bind[] = &$params[$i];
  }
  call_user_func_array([$stmt, "bind_param"], $bind);
}

/**
 * Renderiza tabla con cabeceras clicables para ordenar.
 * - $columns: array de:
 *     ["key"=>"fecha","label"=>"Fecha"] (sortable por defecto)
 *     ["key"=>"acciones","label"=>"Acciones","sortable"=>false] (sin sort)
 * - $rows: array de filas, donde cada celda puede ser:
 *     - string/number (se escapa)
 *     - ["_html" => "<a ...>Ver</a>"] (se imprime como HTML ya construido)
 */
function renderTablaOrdenable(array $columns, array $rows, string $sortKey, string $sortDir): void {
  echo "<table>";
  echo "<tr>";

  foreach ($columns as $col) {
    $key = $col["key"];
    $label = $col["label"];
    $sortable = !isset($col["sortable"]) ? true : (bool)$col["sortable"];

    // Si no es sortable, pintamos texto plano.
    if (!$sortable) {
      echo "<th>".e($label)."</th>";
      continue;
    }

    // Toggle asc/desc si clicas la misma columna
    $nextDir = "asc";
    $arrow = "";

    if ($sortKey === $key) {
      if ($sortDir === "asc") {
        $nextDir = "desc";
        $arrow = " ▲";
      } else {
        $nextDir = "asc";
        $arrow = " ▼";
      }
    }

    $href = urlCon(["sort" => $key, "dir" => $nextDir]);

    echo "<th><a class='sort' href='".e($href)."'>".e($label.$arrow)."</a></th>";
  }

  echo "</tr>";

  if (count($rows) > 0) {
    foreach ($rows as $fila) {
      echo "<tr>";
      foreach ($fila as $celda) {

        // Celda HTML "raw" (la construimos nosotros)
        if (is_array($celda) && isset($celda["_html"])) {
          echo "<td>".$celda["_html"]."</td>";
          continue;
        }

        $v = ($celda === null || $celda === "") ? "-" : $celda;
        echo "<td>".e($v)."</td>";
      }
      echo "</tr>";
    }
  } else {
    echo "<tr><td colspan='".count($columns)."'>No hay registros.</td></tr>";
  }

  echo "</table>";
}

/**
 * Carga lista de campos para el filtro (select).
 */
function cargarCampos(mysqli $conexion): array {
  $campos = [];
  $res = $conexion->query("SELECT id, nombre, tipo FROM campo ORDER BY nombre ASC");
  if ($res) {
    while ($r = $res->fetch_assoc()) {
      $campos[] = $r; // id, nombre, tipo
    }
  }
  return $campos;
}

/**
 * Formulario de filtros para vistas JOIN (reservas y líneas).
 */
function renderFiltrosJoin(array $campos, string $q, string $fecha, int $campo_id, string $tabla): void {
  echo "<form class='filters' method='GET' action='?'>";
  echo "<input type='hidden' name='tabla' value='".e($tabla)."'>";

  echo "<div class='field grow'>";
  echo "<label>Buscar cliente</label>";
  echo "<input type='text' name='q' value='".e($q)."' placeholder='Nombre, email o teléfono'>";
  echo "</div>";

  echo "<div class='field'>";
  echo "<label>Fecha</label>";
  echo "<input type='date' name='fecha' value='".e($fecha)."'>";
  echo "</div>";

  echo "<div class='field'>";
  echo "<label>Campo</label>";
  echo "<select name='campo_id'>";
  echo "<option value=''>Todos</option>";
  foreach ($campos as $c) {
    $id = (int)$c["id"];
    $txt = trim((string)$c["nombre"]." · ".(string)$c["tipo"]);
    $sel = ($campo_id === $id) ? " selected" : "";
    echo "<option value='".e($id)."'".$sel.">".e($txt)."</option>";
  }
  echo "</select>";
  echo "</div>";

  echo "<div class='actions'>";
  echo "<button class='btn primary' type='submit'>Aplicar</button>";
  echo "<a class='btn' href='".e(urlCon(["q"=>null, "fecha"=>null, "campo_id"=>null, "sort"=>null, "dir"=>null, "ver"=>null]))."'>Limpiar</a>";
  echo "</div>";

  echo "</form>";
}

/**
 * Formulario de filtros para clientes (solo búsqueda).
 */
function renderFiltroClientes(string $q, string $tabla): void {
  echo "<form class='filters' method='GET' action='?'>";
  echo "<input type='hidden' name='tabla' value='".e($tabla)."'>";

  echo "<div class='field grow'>";
  echo "<label>Buscar</label>";
  echo "<input type='text' name='q' value='".e($q)."' placeholder='Nombre, apellidos, email o teléfono'>";
  echo "</div>";

  echo "<div class='actions'>";
  echo "<button class='btn primary' type='submit'>Aplicar</button>";
  echo "<a class='btn' href='".e(urlCon(["q"=>null, "sort"=>null, "dir"=>null]))."'>Limpiar</a>";
  echo "</div>";

  echo "</form>";
}

/* ============================
   4) Título de sección
   ============================ */
echo "<h2 style='margin:0 0 14px; font-size:18px; letter-spacing:.2px;'>";
echo e($TABLAS_PERMITIDAS[$tabla]);
echo "</h2>";

/* ============================
   5) Parámetros comunes
   ============================ */
$q = trim((string)($_GET["q"] ?? ""));
$fecha = trim((string)($_GET["fecha"] ?? ""));   // para JOIN (reserva.fecha)
$campo_id = (int)($_GET["campo_id"] ?? 0);       // para JOIN

/* ============================================================
   6) DETALLE: RESERVA (?tabla=reserva&ver=ID)
   ============================================================ */
if ($tabla === "reserva" && isset($_GET["ver"]) && ctype_digit((string)$_GET["ver"])) {

  $reserva_id = (int)$_GET["ver"];

  // Botón volver (mantiene filtros/orden; solo quita "ver")
  echo "<div style='margin:0 0 14px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;'>";
  echo "<a class='btn' href='".e(urlCon(["ver"=>null]))."'>← Volver</a>";
  echo "</div>";

  // 1) Cabecera: reserva + cliente
  $sqlCab = "
    SELECT
      r.id    AS reserva_id,
      r.fecha AS fecha,
      c.id    AS cliente_id,
      c.nombre,
      c.apellidos,
      c.email,
      c.telefono
    FROM reserva r
    LEFT JOIN cliente c ON c.id = r.cliente_id
    WHERE r.id = ?
    LIMIT 1
  ";

  $cab = null;
  $stmt = $conexion->prepare($sqlCab);
  if ($stmt) {
    $stmt->bind_param("i", $reserva_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $cab = $res ? $res->fetch_assoc() : null;
    $stmt->close();
  }

  if (!$cab) {
    echo "<p>No se encontró la reserva.</p>";
    return;
  }

  $clienteNombre = trim((string)$cab["nombre"]." ".(string)$cab["apellidos"]);
  if ($clienteNombre === "") $clienteNombre = "-";

  // 2) Líneas: lineareserva + campo
  $sqlLin = "
    SELECT
      lr.id       AS linea_id,
      lr.dia      AS dia,
      lr.hora     AS hora,
      lr.duracion AS duracion,
      ca.id       AS campo_id,
      ca.nombre   AS campo_nombre,
      ca.tipo     AS campo_tipo,
      ca.precio_hora AS campo_precio_hora
    FROM lineareserva lr
    LEFT JOIN campo ca ON ca.id = lr.campo_id
    WHERE lr.reserva_id = ?
    ORDER BY lr.id ASC
  ";

  $lineas = [];
  $stmt = $conexion->prepare($sqlLin);
  if ($stmt) {
    $stmt->bind_param("i", $reserva_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
      while ($r = $res->fetch_assoc()) {
        $lineas[] = $r;
      }
    }
    $stmt->close();
  }

  // 3) Render: cabecera en tabla 2 columnas
  echo "<h3 style='margin:0 0 10px;'>Reserva #".e($cab["reserva_id"])."</h3>";

  echo "<table>";
  echo "<tr><th>Campo</th><th>Valor</th></tr>";
  echo "<tr><td>ID Reserva</td><td>".e($cab["reserva_id"])."</td></tr>";
  echo "<tr><td>Fecha</td><td>".e($cab["fecha"])."</td></tr>";
  echo "<tr><td>Cliente</td><td>".e($clienteNombre)."</td></tr>";
  echo "<tr><td>Email</td><td>".e($cab["email"])."</td></tr>";
  echo "<tr><td>Teléfono</td><td>".e($cab["telefono"])."</td></tr>";
  echo "</table>";

  echo "<div style='height:14px;'></div>";

  // 4) Render: líneas
  echo "<h3 style='margin:0 0 10px;'>Líneas de reserva</h3>";

  $cols = [
    ["key"=>"linea",    "label"=>"Línea",    "sortable"=>false],
    ["key"=>"dia",      "label"=>"Día",      "sortable"=>false],
    ["key"=>"hora",     "label"=>"Hora",     "sortable"=>false],
    ["key"=>"duracion", "label"=>"Duración", "sortable"=>false],
    ["key"=>"campo",    "label"=>"Campo",    "sortable"=>false],
    ["key"=>"tipo",     "label"=>"Tipo",     "sortable"=>false],
    ["key"=>"precio",   "label"=>"Precio/h", "sortable"=>false],
  ];

  $rows = [];
  foreach ($lineas as $ln) {
    $rows[] = [
      $ln["linea_id"],
      $ln["dia"],
      $ln["hora"],
      $ln["duracion"],
      $ln["campo_nombre"],
      $ln["campo_tipo"],
      $ln["campo_precio_hora"],
    ];
  }

  renderTablaOrdenable($cols, $rows, "", "asc");
  return;
}

/* ============================================================
   7) LISTADO: RESERVAS (JOIN + filtros + orden + acciones)
   ============================================================ */
if ($tabla === "reserva") {

  $campos = cargarCampos($conexion);
  renderFiltrosJoin($campos, $q, $fecha, $campo_id, $tabla);

  $columns = [
    ["key"=>"reserva",  "label"=>"Reserva"],
    ["key"=>"fecha",    "label"=>"Fecha"],
    ["key"=>"cliente",  "label"=>"Cliente"],
    ["key"=>"email",    "label"=>"Email"],
    ["key"=>"telefono", "label"=>"Teléfono"],
    ["key"=>"linea",    "label"=>"Línea"],
    ["key"=>"dia",      "label"=>"Día"],
    ["key"=>"hora",     "label"=>"Hora"],
    ["key"=>"duracion", "label"=>"Duración"],
    ["key"=>"campo",    "label"=>"Campo"],
    ["key"=>"tipo",     "label"=>"Tipo"],
    ["key"=>"precio",   "label"=>"Precio/h"],
    ["key"=>"acciones", "label"=>"Acciones", "sortable"=>false],
  ];

  // Mapa de ORDER BY (lista blanca)
  $SORT_MAP = [
    "reserva"  => ["r.id"],
    "fecha"    => ["r.fecha"],
    "cliente"  => ["c.apellidos", "c.nombre"],
    "email"    => ["c.email"],
    "telefono" => ["c.telefono"],
    "linea"    => ["lr.id"],
    "dia"      => ["lr.dia"],
    "hora"     => ["lr.hora"],
    "duracion" => ["lr.duracion"],
    "campo"    => ["ca.nombre"],
    "tipo"     => ["ca.tipo"],
    "precio"   => ["ca.precio_hora"],
  ];

  $sortKey = (string)($_GET["sort"] ?? "reserva");
  if (!isset($SORT_MAP[$sortKey])) $sortKey = "reserva";

  $sortDir = strtolower((string)($_GET["dir"] ?? "desc"));
  if ($sortDir !== "asc" && $sortDir !== "desc") $sortDir = "desc";

  // WHERE dinámico (prepared)
  $where = [];
  $types = "";
  $params = [];

  if ($q !== "") {
    $where[] = "(c.nombre LIKE ? OR c.apellidos LIKE ? OR c.email LIKE ? OR c.telefono LIKE ?)";
    $like = "%".$q."%";
    $types .= "ssss";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
  }

  if ($fecha !== "") {
    // reserva.fecha es VARCHAR => filtramos por prefijo YYYY-MM-DD
    $where[] = "r.fecha LIKE ?";
    $types .= "s";
    $params[] = $fecha."%";
  }

  if ($campo_id > 0) {
    $where[] = "ca.id = ?";
    $types .= "i";
    $params[] = $campo_id;
  }

  $sql = "
    SELECT
      r.id        AS reserva_id,
      r.fecha     AS fecha,
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
    LEFT JOIN cliente c
      ON c.id = r.cliente_id
    LEFT JOIN lineareserva lr
      ON lr.reserva_id = r.id
    LEFT JOIN campo ca
      ON ca.id = lr.campo_id
  ";

  if (count($where) > 0) {
    $sql .= " WHERE ".implode(" AND ", $where);
  }

  // ORDER BY seguro
  $orderParts = [];
  foreach ($SORT_MAP[$sortKey] as $col) {
    $orderParts[] = $col." ".$sortDir;
  }

  // Orden secundario estable
  if ($sortKey !== "reserva") {
    $orderParts[] = "r.id DESC";
    $orderParts[] = "lr.id ASC";
  } else {
    $orderParts[] = "lr.id ASC";
  }

  $sql .= " ORDER BY ".implode(", ", $orderParts);

  $rows = [];

  $stmt = $conexion->prepare($sql);
  if ($stmt) {
    if ($types !== "") {
      stmtBind($stmt, $types, $params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $ultimaReserva = null;

    if ($res) {
      while ($r = $res->fetch_assoc()) {
        $cliente = trim((string)$r["cliente_nombre"]." ".(string)$r["cliente_apellidos"]);
        if ($cliente === "") $cliente = "-";

        // Acción "Ver" (solo la mostramos una vez por reserva para no repetir)
        $htmlAcciones = "";
        if ($ultimaReserva !== $r["reserva_id"]) {
          $hrefVer = urlCon(["ver" => (int)$r["reserva_id"]]);
          $htmlAcciones = "<a class='btn' href='".e($hrefVer)."'>Ver</a>";
          $ultimaReserva = $r["reserva_id"];
        }

        $rows[] = [
          $r["reserva_id"],
          $r["fecha"],
          $cliente,
          $r["cliente_email"],
          $r["cliente_telefono"],
          $r["linea_id"],
          $r["dia"],
          $r["hora"],
          $r["duracion"],
          $r["campo_nombre"],
          $r["campo_tipo"],
          $r["campo_precio_hora"],
          ["_html" => $htmlAcciones],
        ];
      }
    }

    $stmt->close();
  }

  renderTablaOrdenable($columns, $rows, $sortKey, $sortDir);
  return;
}

/* ============================================================
   8) LISTADO: LÍNEAS (JOIN + filtros + orden)
   ============================================================ */
if ($tabla === "lineareserva") {

  $campos = cargarCampos($conexion);
  renderFiltrosJoin($campos, $q, $fecha, $campo_id, $tabla);

  $columns = [
    ["key"=>"linea",    "label"=>"Línea"],
    ["key"=>"dia",      "label"=>"Día"],
    ["key"=>"hora",     "label"=>"Hora"],
    ["key"=>"duracion", "label"=>"Duración"],
    ["key"=>"campo",    "label"=>"Campo"],
    ["key"=>"tipo",     "label"=>"Tipo"],
    ["key"=>"precio",   "label"=>"Precio/h"],
    ["key"=>"reserva",  "label"=>"Reserva"],
    ["key"=>"fecha",    "label"=>"Fecha"],
    ["key"=>"cliente",  "label"=>"Cliente"],
    ["key"=>"email",    "label"=>"Email"],
    ["key"=>"telefono", "label"=>"Teléfono"],
  ];

  $SORT_MAP = [
    "linea"    => ["lr.id"],
    "dia"      => ["lr.dia"],
    "hora"     => ["lr.hora"],
    "duracion" => ["lr.duracion"],
    "campo"    => ["ca.nombre"],
    "tipo"     => ["ca.tipo"],
    "precio"   => ["ca.precio_hora"],
    "reserva"  => ["r.id"],
    "fecha"    => ["r.fecha"],
    "cliente"  => ["c.apellidos", "c.nombre"],
    "email"    => ["c.email"],
    "telefono" => ["c.telefono"],
  ];

  $sortKey = (string)($_GET["sort"] ?? "linea");
  if (!isset($SORT_MAP[$sortKey])) $sortKey = "linea";

  $sortDir = strtolower((string)($_GET["dir"] ?? "desc"));
  if ($sortDir !== "asc" && $sortDir !== "desc") $sortDir = "desc";

  $where = [];
  $types = "";
  $params = [];

  if ($q !== "") {
    $where[] = "(c.nombre LIKE ? OR c.apellidos LIKE ? OR c.email LIKE ? OR c.telefono LIKE ?)";
    $like = "%".$q."%";
    $types .= "ssss";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
  }

  if ($fecha !== "") {
    $where[] = "r.fecha LIKE ?";
    $types .= "s";
    $params[] = $fecha."%";
  }

  if ($campo_id > 0) {
    $where[] = "ca.id = ?";
    $types .= "i";
    $params[] = $campo_id;
  }

  $sql = "
    SELECT
      lr.id       AS linea_id,
      lr.dia      AS dia,
      lr.hora     AS hora,
      lr.duracion AS duracion,

      ca.nombre   AS campo_nombre,
      ca.tipo     AS campo_tipo,
      ca.precio_hora AS campo_precio_hora,

      r.id        AS reserva_id,
      r.fecha     AS fecha,

      c.nombre    AS cliente_nombre,
      c.apellidos AS cliente_apellidos,
      c.email     AS cliente_email,
      c.telefono  AS cliente_telefono
    FROM lineareserva lr
    LEFT JOIN campo ca
      ON ca.id = lr.campo_id
    LEFT JOIN reserva r
      ON r.id = lr.reserva_id
    LEFT JOIN cliente c
      ON c.id = r.cliente_id
  ";

  if (count($where) > 0) {
    $sql .= " WHERE ".implode(" AND ", $where);
  }

  $orderParts = [];
  foreach ($SORT_MAP[$sortKey] as $col) {
    $orderParts[] = $col." ".$sortDir;
  }
  if ($sortKey !== "linea") {
    $orderParts[] = "lr.id DESC";
  }
  $sql .= " ORDER BY ".implode(", ", $orderParts);

  $rows = [];

  $stmt = $conexion->prepare($sql);
  if ($stmt) {
    if ($types !== "") {
      stmtBind($stmt, $types, $params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res) {
      while ($r = $res->fetch_assoc()) {
        $cliente = trim((string)$r["cliente_nombre"]." ".(string)$r["cliente_apellidos"]);
        if ($cliente === "") $cliente = "-";

        $rows[] = [
          $r["linea_id"],
          $r["dia"],
          $r["hora"],
          $r["duracion"],
          $r["campo_nombre"],
          $r["campo_tipo"],
          $r["campo_precio_hora"],
          $r["reserva_id"],
          $r["fecha"],
          $cliente,
          $r["cliente_email"],
          $r["cliente_telefono"],
        ];
      }
    }

    $stmt->close();
  }

  renderTablaOrdenable($columns, $rows, $sortKey, $sortDir);
  return;
}

/* ============================================================
   9) EXTRA: CLIENTES (búsqueda + orden por click)
   ============================================================ */
if ($tabla === "cliente") {

  renderFiltroClientes($q, $tabla);

  $columns = [
    ["key"=>"id",        "label"=>"ID"],
    ["key"=>"nombre",    "label"=>"Nombre"],
    ["key"=>"apellidos", "label"=>"Apellidos"],
    ["key"=>"email",     "label"=>"Email"],
    ["key"=>"telefono",  "label"=>"Teléfono"],
  ];

  $SORT_MAP = [
    "id"        => ["id"],
    "nombre"    => ["nombre"],
    "apellidos" => ["apellidos"],
    "email"     => ["email"],
    "telefono"  => ["telefono"],
  ];

  $sortKey = (string)($_GET["sort"] ?? "id");
  if (!isset($SORT_MAP[$sortKey])) $sortKey = "id";

  $sortDir = strtolower((string)($_GET["dir"] ?? "asc"));
  if ($sortDir !== "asc" && $sortDir !== "desc") $sortDir = "asc";

  $where = [];
  $types = "";
  $params = [];

  if ($q !== "") {
    $where[] = "(nombre LIKE ? OR apellidos LIKE ? OR email LIKE ? OR telefono LIKE ?)";
    $like = "%".$q."%";
    $types .= "ssss";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
  }

  $sql = "SELECT id, nombre, apellidos, email, telefono FROM cliente";
  if (count($where) > 0) {
    $sql .= " WHERE ".implode(" AND ", $where);
  }

  $orderParts = [];
  foreach ($SORT_MAP[$sortKey] as $col) {
    $orderParts[] = $col." ".$sortDir;
  }
  $sql .= " ORDER BY ".implode(", ", $orderParts);

  $rows = [];

  $stmt = $conexion->prepare($sql);
  if ($stmt) {
    if ($types !== "") {
      stmtBind($stmt, $types, $params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res) {
      while ($r = $res->fetch_assoc()) {
        $rows[] = [
          $r["id"],
          $r["nombre"],
          $r["apellidos"],
          $r["email"],
          $r["telefono"],
        ];
      }
    }

    $stmt->close();
  }

  renderTablaOrdenable($columns, $rows, $sortKey, $sortDir);
  return;
}

/* ============================================================
   10) VISTA GENÉRICA: SELECT * tabla
   (campo y otras)
   ============================================================ */

/* Cabecera: LIMIT 0 para obtener metadatos */
$headerRes = $conexion->query("SELECT * FROM ".$tabla." LIMIT 0;");
$fields = $headerRes ? $headerRes->fetch_fields() : [];
$colCount = is_array($fields) ? count($fields) : 0;

echo "<table>";

if ($colCount > 0) {
  echo "<tr>";
  foreach ($fields as $f) {
    echo "<th>".e(labelBonito($f->name))."</th>";
  }
  echo "</tr>";
} else {
  echo "<tr><th>Sin columnas</th></tr>";
}

/* Cuerpo */
$resultado = $conexion->query("SELECT * FROM ".$tabla);

if ($resultado && $resultado->num_rows > 0) {
  while ($fila = $resultado->fetch_assoc()) {
    echo "<tr>";
    foreach ($fila as $clave => $valor) {
      if ($valor === null) $valor = "-";
      echo "<td>".e($valor)."</td>";
    }
    echo "</tr>";
  }
} else {
  $span = ($colCount > 0) ? $colCount : 1;
  echo "<tr><td colspan='".$span."'>No hay registros.</td></tr>";
}

echo "</table>";
?>
