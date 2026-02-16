<?php
/**
 * back/inc/views/cliente_form.php
 * Formulario para crear cliente
 */

echo "<div style='margin:0 0 12px; display:flex; gap:10px; align-items:center;'>";
echo "<a class='btn' href='?tabla=cliente'>← Volver</a>";
echo "</div>";

echo "<form method='POST' action='?tabla=cliente&accion=nuevo' class='filters'>";
echo "<input type='hidden' name='crear_cliente' value='1'>";

echo "<div class='field grow'><label>Nombre</label><input type='text' name='nombre' placeholder='Nombre' required></div>";
echo "<div class='field grow'><label>Apellidos</label><input type='text' name='apellidos' placeholder='Apellidos'></div>";
echo "<div class='field grow'><label>Email</label><input type='text' name='email' placeholder='Email (opcional si hay teléfono)'></div>";
echo "<div class='field'><label>Teléfono</label><input type='text' name='telefono' placeholder='Teléfono (opcional si hay email)'></div>";

echo "<div class='actions'>";
echo "<button class='btn primary' type='submit'>Crear</button>";
echo "<a class='btn' href='?tabla=cliente'>Cancelar</a>";
echo "</div>";

echo "</form>";
