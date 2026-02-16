<?php
/**
 * back/inc/views/reserva_form.php
 * Formulario para crear reserva
 */

$clientes = getClientes($cn);
$campos   = getCampos($cn);

$fecha_default = date("Y-m-d\\TH:i");
$dia_default   = date("Y-m-d");

echo "<div style='margin:0 0 12px; display:flex; gap:10px; align-items:center;'>";
echo "<a class='btn' href='?tabla=reserva'>← Volver</a>";
echo "</div>";

echo "<form method='POST' action='?tabla=reserva&accion=nueva' class='filters' style='margin-top:6px;'>";
echo "<input type='hidden' name='crear_reserva' value='1'>";

echo "<div class='field grow'>";
echo "<label>Cliente (existente)</label>";
echo "<select name='cliente_id'>";
echo "<option value='0'>Selecciona un cliente</option>";
foreach ($clientes as $c) {
  $label = trim($c["nombre"] . " " . $c["apellidos"]);
  $extra = trim((string)$c["email"]);
  $txt = "#" . $c["id"] . " - " . $label;
  if ($extra !== "") $txt .= " (" . $extra . ")";
  echo "<option value='" . e($c["id"]) . "'>" . e($txt) . "</option>";
}
echo "</select>";
echo "</div>";

echo "<div class='field'>";
echo "<label>Cliente nuevo</label>";
echo "<div style='display:flex; gap:8px; align-items:center; padding:10px 12px; border-radius:12px; border:1px solid rgba(255,255,255,.14); background:rgba(255,255,255,.06);'>";
echo "<input type='checkbox' id='cliente_nuevo' name='cliente_nuevo' value='1'>";
echo "<label for='cliente_nuevo' style='margin:0; font-size:14px; opacity:.95;'>Crear cliente nuevo</label>";
echo "</div>";
echo "</div>";

echo "<div class='field grow'><label>Nombre (cliente nuevo)</label><input type='text' name='nuevo_nombre' placeholder='Nombre'></div>";
echo "<div class='field grow'><label>Apellidos (cliente nuevo)</label><input type='text' name='nuevo_apellidos' placeholder='Apellidos'></div>";
echo "<div class='field grow'><label>Email (cliente nuevo)</label><input type='text' name='nuevo_email' placeholder='Email (opcional si hay teléfono)'></div>";
echo "<div class='field'><label>Teléfono (cliente nuevo)</label><input type='text' name='nuevo_telefono' placeholder='Teléfono (opcional si hay email)'></div>";

echo "<div class='field'><label>Fecha (reserva)</label><input type='datetime-local' name='fecha' value='" . e($fecha_default) . "' required></div>";

echo "<div class='field grow'>";
echo "<label>Campo</label>";
echo "<select name='campo_id' required>";
echo "<option value='0'>Selecciona un campo</option>";
foreach ($campos as $ca) {
  $txt = "#" . $ca["id"] . " - " . $ca["nombre"] . " · " . $ca["tipo"] . " · " . $ca["precio_hora"];
  echo "<option value='" . e($ca["id"]) . "'>" . e($txt) . "</option>";
}
echo "</select>";
echo "</div>";

echo "<div class='field'><label>Día</label><input type='date' name='dia' value='" . e($dia_default) . "' required></div>";
echo "<div class='field'><label>Hora</label><input type='time' name='hora' value='15:00' required></div>";
echo "<div class='field'><label>Duración</label><input type='text' name='duracion' value='1' placeholder='Ej: 1 / 2' required></div>";

echo "<div class='actions'>";
echo "<button class='btn primary' type='submit'>Crear</button>";
echo "<a class='btn' href='?tabla=reserva'>Cancelar</a>";
echo "</div>";

echo "</form>";
