<?php
/**
 * inc/tabla.php — Visor de tablas
 * ------------------------------------------------------------
 * Objetivos:
 *  1) Panel "conectado" a la misma configuración de BD del index.php
 *  2) Labels/cabeceras más "humanizadas".
 *  3) Mejor comportamiento cuando una tabla está vacía (cabecera siempre).
 */

/* ============================
   1) Tablas permitidas
   ============================ */
$TABLAS_PERMITIDAS = [
  "reserva"  => "Reservas",
  "campo"    => "Campos",
  "cliente"  => "Clientes",
  "lineareserva" => "Líneas de reserva",

];

/* Tabla actual (por defecto: reserva) */
$tabla = $_GET["tabla"] ?? "reserva";
if (!isset($TABLAS_PERMITIDAS[$tabla])) {
  $tabla = "reserva";
}

/* ============================
   2) Conexión usando la config del index.php
   ============================ */
if (!isset($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME)) {
  // Fallback (por si abren inc/tabla.php directo)
  $DB_HOST = "localhost";
  $DB_USER = "reserva_empresa";
  $DB_PASS = "Reservaempresa123_";
  $DB_NAME = "reserva_empresa";
}

$conexion = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$conexion->set_charset("utf8mb4");

/* ============================
   3) Helper: label bonito para columnas
   ============================ */
function labelBonito(string $col): string {
  // Mapa de traducción rápida
  $map = [
    "id"          => "ID",
    "nombre"      => "Nombre",
    "telefono"    => "Teléfono",
    "email"       => "Email",
    "fecha"       => "Fecha",
    "hora"        => "Hora",
    "hora_inicio" => "Inicio",
    "hora_fin"    => "Fin",
    "estado"      => "Estado",
    "precio"      => "Precio",
    "precio_hora" => "Precio/h",
    "created_at"  => "Creado",
    "updated_at"  => "Actualizado",

// Claves FK reales del modelo
"cliente_id" => "Cliente (ID)",
"reserva_id" => "Reserva (ID)",
"campo_id"   => "Campo (ID)",

  ];

  if (isset($map[$col])) return $map[$col];

  // Fallback genérico: snake_case -> "Snake Case"
  $s = str_replace(["_", "-"], " ", $col);
  $s = mb_convert_case($s, MB_CASE_TITLE, "UTF-8");

  // Pequeña mejora: "Id" -> "ID"
  $s = preg_replace('/\bId\b/u', 'ID', $s);

  return $s;
}

/* ============================
   4) Pintar título de sección
   ============================ */
echo "<h2 style='margin:0 0 14px; font-size:18px; letter-spacing:.2px;'>";
echo htmlspecialchars($TABLAS_PERMITIDAS[$tabla], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
echo "</h2>";

/* ============================
   5) Cabecera de tabla (siempre)
   Usamos LIMIT 0 para traer solo metadatos de columnas
   ============================ */
$headerRes = $conexion->query("SELECT * FROM ".$tabla." LIMIT 0;");
$fields = $headerRes ? $headerRes->fetch_fields() : [];
$colCount = is_array($fields) ? count($fields) : 0;

echo "<table>";

if ($colCount > 0) {
  echo "<tr>";
  foreach ($fields as $f) {
    echo "<th>".htmlspecialchars(labelBonito($f->name), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8")."</th>";
  }
  echo "</tr>";
} else {
  // Si no pudimos leer columnas (tabla no existe o error), mostramos algo básico
  echo "<tr><th>Sin columnas</th></tr>";
}

/* ============================
   6) Cuerpo de la tabla
   ============================ */
$resultado = $conexion->query("SELECT * FROM ".$tabla);

if ($resultado && $resultado->num_rows > 0) {
  while ($fila = $resultado->fetch_assoc()) {
    echo "<tr>";
    foreach ($fila as $clave => $valor) {
      // Formateo simple:
      // - NULL -> "-"
      // - Todo escapado para no romper HTML
      if ($valor === null) $valor = "-";
      echo "<td>".htmlspecialchars((string)$valor, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8")."</td>";
    }
    echo "</tr>";
  }
} else {
  // Tabla vacía
  $span = ($colCount > 0) ? $colCount : 1;
  echo "<tr><td colspan='".$span."'>No hay registros.</td></tr>";
}

echo "</table>";

