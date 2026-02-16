<?php
/**
 * inc/tabla.php — Visor de tablas (Reservas JOIN + Líneas JOIN)
 * ------------------------------------------------------------
 * Objetivos:
 *  1) Mantener el visor genérico (SELECT * tabla) para tablas normales.
 *  2) Para la tabla "reserva", mostrar una vista con JOIN:
 *     - reserva (cabecera: fecha + cliente_id)
 *     - cliente (nombre, apellidos, email, telefono)
 *     - lineareserva (dia, hora, duracion)
 *     - campo (nombre, tipo, precio_hora)
 *  3) Para la tabla "lineareserva", otra vista con JOIN:
 *     - lineareserva + campo + reserva + cliente
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

/**
 * Convierte un nombre de columna técnico a una etiqueta más humana.
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

  // snake_case -> "Snake Case"
  $s = str_replace(["_", "-"], " ", $col);
  $s = mb_convert_case($s, MB_CASE_TITLE, "UTF-8");

  // "Id" -> "ID"
  $s = preg_replace('/\bId\b/u', 'ID', $s);

  return $s;
}

/** Escapa texto para HTML */
function e($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

/**
 * Pinta una tabla HTML recibiendo:
 * - $headers: array de nombres de columna (ya humanizados)
 * - $rows: array de filas (cada fila es array de celdas)
 */
function renderTabla(array $headers, array $rows): void {
  echo "<table>";

  // Cabecera
  echo "<tr>";
  foreach ($headers as $h) {
    echo "<th>".e($h)."</th>";
  }
  echo "</tr>";

  // Cuerpo
  if (count($rows) > 0) {
    foreach ($rows as $fila) {
      echo "<tr>";
      foreach ($fila as $celda) {
        echo "<td>".e($celda === null || $celda === "" ? "-" : $celda)."</td>";
      }
      echo "</tr>";
    }
  } else {
    echo "<tr><td colspan='".count($headers)."'>No hay registros.</td></tr>";
  }

  echo "</table>";
}

/* ============================
   4) Título de sección
   ============================ */
echo "<h2 style='margin:0 0 14px; font-size:18px; letter-spacing:.2px;'>";
echo e($TABLAS_PERMITIDAS[$tabla]);
echo "</h2>";

/* ============================
   5) VISTA ESPECIAL: Reservas con JOIN
   ============================ */
if ($tabla === "reserva") {

  /**
   * 1 fila por "línea de reserva".
   * Si una reserva tiene varias líneas, verás varias filas con el mismo reserva_id.
   */
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
    ORDER BY r.id DESC, lr.id ASC
  ";

  $res = $conexion->query($sql);

  $headers = [
    "Reserva",
    "Fecha",
    "Cliente",
    "Email",
    "Teléfono",
    "Línea",
    "Día",
    "Hora",
    "Duración",
    "Campo",
    "Tipo",
    "Precio/h",
  ];

  $rows = [];

  if ($res) {
    while ($r = $res->fetch_assoc()) {
      $cliente = trim((string)$r["cliente_nombre"]." ".(string)$r["cliente_apellidos"]);
      if ($cliente === "") $cliente = "-";

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
      ];
    }
  }

  renderTabla($headers, $rows);
  return; // evita que se pinte la vista genérica debajo
}

/* ============================
   6) VISTA ESPECIAL: Líneas con JOIN
   ============================ */
if ($tabla === "lineareserva") {

  /**
   * 1 fila por línea (lo más operativo):
   * - Línea: día/hora/duración
   * - Campo: nombre/tipo/precio
   * - Reserva: id/fecha
   * - Cliente: nombre/apellidos/email/teléfono
   */
  $sql = "
    SELECT
      lr.id       AS linea_id,
      lr.dia      AS dia,
      lr.hora     AS hora,
      lr.duracion AS duracion,

      ca.id       AS campo_id,
      ca.nombre   AS campo_nombre,
      ca.tipo     AS campo_tipo,
      ca.precio_hora AS campo_precio_hora,

      r.id        AS reserva_id,
      r.fecha     AS fecha,

      c.id        AS cliente_id,
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
    ORDER BY lr.id DESC
  ";

  $res = $conexion->query($sql);

  $headers = [
    "Línea",
    "Día",
    "Hora",
    "Duración",
    "Campo",
    "Tipo",
    "Precio/h",
    "Reserva",
    "Fecha",
    "Cliente",
    "Email",
    "Teléfono",
  ];

  $rows = [];

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

  renderTabla($headers, $rows);
  return; // evita que se pinte la vista genérica debajo
}

/* ============================
   7) VISTA GENÉRICA: SELECT * tabla
   (campo, cliente, etc.)
   ============================ */

/**
 * Cabecera:
 * - usamos LIMIT 0 para obtener solo metadatos de columnas
 */
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
