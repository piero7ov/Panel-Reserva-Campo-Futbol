<?php
/**
 * back/inc/views/campo_list.php
 * Listado de campos (vista simple)
 */

  $res  = $cn->query("SELECT * FROM campo ORDER BY id DESC");
  $head = $cn->query("SELECT * FROM campo LIMIT 0");
  $fields = $head ? $head->fetch_fields() : [];

  echo "<table>";
  if ($fields) {
    echo "<tr>";
    foreach ($fields as $f) echo "<th>" . e($f->name) . "</th>";
    echo "</tr>";
  }

  if ($res && $res->num_rows > 0) {
    while ($r = $res->fetch_assoc()) {
      echo "<tr>";
      foreach ($r as $v) echo "<td>" . e($v === null ? "-" : $v) . "</td>";
      echo "</tr>";
    }
  } else {
    echo "<tr><td colspan='" . (count($fields) ?: 1) . "'>No hay registros.</td></tr>";
  }

  echo "</table>";
