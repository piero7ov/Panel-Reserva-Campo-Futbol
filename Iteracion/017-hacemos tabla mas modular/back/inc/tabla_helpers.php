<?php
/**
 * back/inc/tabla_helpers.php
 * Funciones auxiliares para tabla.php
 */

/* ============================
   Helpers Generales
   ============================ */

function e($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

function js_redirect(string $url): void {
  echo "<script>window.location.href = " . json_encode($url) . ";</script>";
  exit;
}

/** datetime-local (YYYY-MM-DDTHH:MM) -> (YYYY-MM-DD HH:MM:SS) */
function normalizarDatetimeLocal(string $s): string {
  $s = trim($s);
  if ($s === "") return "";
  $s = str_replace("T", " ", $s);
  if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $s)) $s .= ":00";
  return $s;
}

function to_int($v): int {
  return (int)($v ?? 0);
}

/** Avisos (usa .notice del CSS; coloreamos con inline) */
function notice_html(string $html, string $type = "info"): void {
  $style = "";
  if ($type === "ok") {
    $style = "border-color: rgba(34,197,94,.25); background: rgba(34,197,94,.10);";
  } elseif ($type === "warn") {
    $style = "border-color: rgba(251,191,36,.25); background: rgba(251,191,36,.10);";
  } elseif ($type === "err") {
    $style = "border-color: rgba(239,68,68,.25); background: rgba(239,68,68,.10);";
  }
  echo "<div class='notice' style='{$style}'>" . $html . "</div>";
}

/** Badge por estado (aprovecha tu CSS .badge.*) */
function estado_badge(string $estado): string {
  $estado = strtolower(trim($estado));
  if ($estado !== "confirmada" && $estado !== "cancelada") $estado = "pendiente";

  $label = $estado;
  $label = mb_strtoupper(mb_substr($label, 0, 1), "UTF-8") . mb_substr($label, 1);

  return "<span class='badge " . e($estado) . "'>" . e($label) . "</span>";
}

/* ============================
   Helpers de Datos
   ============================ */

function getClientes(mysqli $cn): array {
  $out = [];
  $sql = "SELECT id, nombre, apellidos, email, telefono FROM cliente ORDER BY id DESC";
  if ($res = $cn->query($sql)) while ($r = $res->fetch_assoc()) $out[] = $r;
  return $out;
}

function getCampos(mysqli $cn): array {
  $out = [];
  $sql = "SELECT id, nombre, tipo, precio_hora FROM campo ORDER BY id DESC";
  if ($res = $cn->query($sql)) while ($r = $res->fetch_assoc()) $out[] = $r;
  return $out;
}

/* ============================
   Helpers de Mantenimiento
   ============================ */

function log_event(mysqli $cn, bool $HAS_LOG_TABLE, string $action, string $level, string $message, array $meta = []) {
    if (!$HAS_LOG_TABLE) return;
    $meta_json = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $st = $cn->prepare("INSERT INTO mantenimiento_log (action, level, message, meta_json) VALUES (?, ?, ?, ?)");
    $st->bind_param("ssss", $action, $level, $message, $meta_json);
    $st->execute();
    $st->close();
}

function human_size(int $bytes): string {
    $u = ["B","KB","MB","GB","TB"];
    $i = 0;
    $v = (float)$bytes;
    while ($v >= 1024 && $i < count($u)-1) { $v /= 1024; $i++; }
    return number_format($v, ($i===0?0:2), ".", "") . " " . $u[$i];
}

function ensure_dir(string $dir): bool {
    if (is_dir($dir)) return is_writable($dir);
    @mkdir($dir, 0775, true);
    return is_dir($dir) && is_writable($dir);
}

function safe_name(string $name): string {
    // Solo permitimos letras, números, guiones, underscores y punto
    return preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
}

function list_backups(string $dir): array {
    if (!is_dir($dir)) return [];
    $files = glob($dir . "/*.sql") ?: [];
    $out = [];
    foreach ($files as $f) {
      $out[] = [
        "name" => basename($f),
        "path" => $f,
        "mtime" => @filemtime($f) ?: 0,
        "size" => @filesize($f) ?: 0,
      ];
    }
    usort($out, fn($a,$b) => $b["mtime"] <=> $a["mtime"]);
    return $out;
}

function dump_db(mysqli $cn, string $dbName, string $filePath, string &$err): bool {
    $err = "";
    $fp = @fopen($filePath, "wb");
    if (!$fp) { $err = "No se pudo crear el archivo de backup."; return false; }

    $now = date("Y-m-d H:i:s");
    fwrite($fp, "-- Backup DB: {$dbName}\n-- Generated: {$now}\n\n");
    fwrite($fp, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    // Tablas base
    $tables = [];
    $resT = $cn->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'");
    if (!$resT) { $err = "No se pudieron listar tablas."; fclose($fp); return false; }
    while ($row = $resT->fetch_array(MYSQLI_NUM)) $tables[] = (string)$row[0];
    $resT->free();

    foreach ($tables as $t) {
      // CREATE TABLE
      $resC = $cn->query("SHOW CREATE TABLE `{$t}`");
      if (!$resC) { $err = "No se pudo leer estructura de {$t}."; fclose($fp); return false; }
      $createRow = $resC->fetch_assoc();
      $resC->free();
      $createSql = $createRow["Create Table"] ?? "";

      fwrite($fp, "\n-- ----------------------------\n");
      fwrite($fp, "-- Table: {$t}\n");
      fwrite($fp, "-- ----------------------------\n");
      fwrite($fp, "DROP TABLE IF EXISTS `{$t}`;\n");
      fwrite($fp, $createSql . ";\n\n");

      // Detectar columnas binarias/blob para exportarlas como 0xHEX
      $binCols = [];
      $stCols = $cn->prepare("
        SELECT COLUMN_NAME, DATA_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
      ");
      $stCols->bind_param("ss", $dbName, $t);
      $stCols->execute();
      $rCols = $stCols->get_result();
      if ($rCols) {
        while ($c = $rCols->fetch_assoc()) {
          $dt = strtolower((string)$c["DATA_TYPE"]);
          if (in_array($dt, ["blob","longblob","mediumblob","tinyblob","binary","varbinary"], true)) {
            $binCols[(string)$c["COLUMN_NAME"]] = true;
          }
        }
      }
      $stCols->close();

      // DATA
      $resD = $cn->query("SELECT * FROM `{$t}`");
      if (!$resD) { $err = "No se pudo leer datos de {$t}."; fclose($fp); return false; }

      if ($resD->num_rows > 0) {
        $fields = $resD->fetch_fields();
        $colNames = array_map(fn($f) => "`" . $f->name . "`", $fields);
        $colList = implode(", ", $colNames);

        while ($r = $resD->fetch_assoc()) {
          $vals = [];
          foreach ($fields as $f) {
            $name = $f->name;
            $v = $r[$name];

            if ($v === null) {
              $vals[] = "NULL";
              continue;
            }

            if (isset($binCols[$name])) {
              $vals[] = "0x" . bin2hex((string)$v);
              continue;
            }

            $vals[] = "'" . $cn->real_escape_string((string)$v) . "'";
          }

          $valList = implode(", ", $vals);
          fwrite($fp, "INSERT INTO `{$t}` ({$colList}) VALUES ({$valList});\n");
        }
      }

      $resD->free();
      fwrite($fp, "\n");
    }

    fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fp);
    return true;
  }
