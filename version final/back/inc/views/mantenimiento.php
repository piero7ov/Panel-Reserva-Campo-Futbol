<?php
/**
 * back/inc/views/mantenimiento.php
 * Vista de mantenimiento: Backups y Logs
 */

  // Config mantenimiento (debe coincidir con tabla_actions.php o ser global)
  $BACKUP_DIR  = dirname(dirname(__DIR__)) . "/backups"; 
  
  // ---- Crear tabla logs si no existe (no rompe si ya existe)
  $cn->query("
    CREATE TABLE IF NOT EXISTS mantenimiento_log (
      id INT AUTO_INCREMENT PRIMARY KEY,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      action VARCHAR(40) NOT NULL,
      level  VARCHAR(10) NOT NULL DEFAULT 'info',
      message TEXT NOT NULL,
      meta_json LONGTEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $HAS_LOG_TABLE = false;
  if ($r = $cn->query("SHOW TABLES LIKE 'mantenimiento_log'")) {
    $HAS_LOG_TABLE = ($r->num_rows > 0);
    $r->free();
  }

  // ---- UI: Backups + Logs
  echo "<div style='display:flex; gap:18px; flex-wrap:wrap; align-items:flex-start;'>";

  /* ====== BLOQUE BACKUPS ====== */
  echo "<div style='flex:1; min-width:320px;'>";
  echo "<h3 style='margin:0 0 10px; font-size:15px;'>Backups</h3>";

  echo "<form method='POST' class='filters' style='margin:0 0 12px;'>";
  echo "<input type='hidden' name='accion_mant' value='backup_create'>";
  echo "<div class='actions'>";
  echo "<button class='btn primary' type='submit'>Crear backup ahora</button>";
  echo "</div>";
  echo "</form>";

  if (!is_dir($BACKUP_DIR)) {
    notice_html("No existe la carpeta <code>/backups</code>.", "warn");
  } elseif (!is_writable($BACKUP_DIR)) {
    notice_html("La carpeta <code>/backups</code> existe pero no tiene permisos de escritura.", "err");
  }

  $files = list_backups($BACKUP_DIR);
  echo "<table>";
  echo "<tr><th>Archivo</th><th>Tamaño</th><th>Fecha</th><th>Acciones</th></tr>";

  if ($files) {
    foreach ($files as $f) {
      $date = $f["mtime"] ? date("Y-m-d H:i:s", $f["mtime"]) : "-";
      $size = (int)$f["size"];

      // Link público (requiere que /backups esté al lado de index.php y sea accesible)
      // Ajuste: si 'back' está en la raiz web, entonces 'backups' sería 'back/backups' o '../backups' relativo a este include.
      // Pero este include se carga en 'index.php' (supuestamente en back/) ? NO, el usuario dijo "back/inc/tabla.php".
      // Asumimos que la estructura es .../back/index.php que hace include de inc/tabla.php.
      // La carpeta backups se crea en dirname(__DIR__) . "/backups" que es back/backups.
      $publicLink = "backups/" . rawurlencode($f["name"]);

      echo "<tr>";
      echo "<td><code>" . e($f["name"]) . "</code></td>";
      echo "<td>" . e(human_size($size)) . "</td>";
      echo "<td>" . e($date) . "</td>";
      echo "<td style='white-space:nowrap; display:flex; gap:8px; flex-wrap:wrap;'>";
      echo "<a class='btn sm' href='" . e($publicLink) . "' download>Descargar</a>";

      echo "<form method='POST' style='margin:0; display:inline;'>";
      echo "<input type='hidden' name='accion_mant' value='backup_delete'>";
      echo "<input type='hidden' name='file' value='" . e($f["name"]) . "'>";
      echo "<button class='btn sm danger' type='submit' onclick='return confirm(\"¿Eliminar este backup?\");'>Eliminar</button>";
      echo "</form>";

      echo "</td>";
      echo "</tr>";
    }
  } else {
    echo "<tr><td colspan='4'>No hay backups aún.</td></tr>";
  }

  echo "</table>";
  echo "</div>";

  /* ====== BLOQUE LOGS ====== */
  echo "<div style='flex:1; min-width:320px;'>";
  echo "<h3 style='margin:0 0 10px; font-size:15px;'>Logs</h3>";

  if (!$HAS_LOG_TABLE) {
    notice_html("No existe la tabla <code>mantenimiento_log</code> (ejecuta el SQL que te pasé).", "warn");
  } else {
    $qlog = trim((string)($_GET["qlog"] ?? ""));
    $lvl  = trim((string)($_GET["lvl"] ?? ""));
    $act  = trim((string)($_GET["act"] ?? ""));

    echo "<form class='filters' method='GET' action='?'>";
    echo "<input type='hidden' name='tabla' value='mantenimiento'>";

    echo "<div class='field grow'><label>Buscar</label><input type='text' name='qlog' value='" . e($qlog) . "' placeholder='Mensaje...'></div>";

    echo "<div class='field'><label>Nivel</label><select name='lvl'>";
    echo "<option value=''>Todos</option>";
    foreach (["info","warn","error"] as $opt) {
      $sel = ($lvl === $opt) ? " selected" : "";
      echo "<option value='" . e($opt) . "'" . $sel . ">" . e($opt) . "</option>";
    }
    echo "</select></div>";

    echo "<div class='field'><label>Acción</label><select name='act'>";
    echo "<option value=''>Todas</option>";
    $actions = ["backup_create","backup_delete","backup_cleanup","error","info"];
    foreach ($actions as $opt) {
      $sel = ($act === $opt) ? " selected" : "";
      echo "<option value='" . e($opt) . "'" . $sel . ">" . e($opt) . "</option>";
    }
    echo "</select></div>";

    echo "<div class='actions'>";
    echo "<button class='btn primary' type='submit'>Aplicar</button>";
    echo "<a class='btn' href='?tabla=mantenimiento'>Limpiar</a>";
    echo "</div>";

    echo "</form>";

    $where = [];
    $types = "";
    $params = [];

    if ($qlog !== "") {
      $where[] = "(message LIKE ? OR meta_json LIKE ?)";
      $like = "%" . $qlog . "%";
      $types .= "ss";
      $params[] = $like; $params[] = $like;
    }
    if ($lvl !== "") {
      $where[] = "level = ?";
      $types .= "s";
      $params[] = $lvl;
    }
    if ($act !== "") {
      $where[] = "action = ?";
      $types .= "s";
      $params[] = $act;
    }

    $sqlL = "SELECT id, created_at, action, level, message, meta_json FROM mantenimiento_log";
    if ($where) $sqlL .= " WHERE " . implode(" AND ", $where);
    $sqlL .= " ORDER BY id DESC LIMIT 200";

    $stL = $cn->prepare($sqlL);
    if ($types !== "") $stL->bind_param($types, ...$params);
    $stL->execute();
    $resL = $stL->get_result();

    echo "<table>";
    echo "<tr><th>Fecha</th><th>Nivel</th><th>Acción</th><th>Mensaje</th></tr>";

    if ($resL && $resL->num_rows > 0) {
      while ($r = $resL->fetch_assoc()) {
        $lvlClass = strtolower((string)$r["level"]);
        $badge = "<span class='badge " . e($lvlClass === "error" ? "cancelada" : ($lvlClass === "warn" ? "pendiente" : "confirmada")) . "'>" . e($lvlClass) . "</span>";
        echo "<tr>";
        echo "<td>" . e($r["created_at"]) . "</td>";
        echo "<td>" . $badge . "</td>";
        echo "<td><code>" . e($r["action"]) . "</code></td>";
        echo "<td>" . e($r["message"]) . "</td>";
        echo "</tr>";
      }
    } else {
      echo "<tr><td colspan='4'>Sin logs.</td></tr>";
    }

    echo "</table>";
    $stL->close();
  }

  echo "</div>";
  echo "</div>";
