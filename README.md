# Panel Reserva Campo Fútbol ⚽
**Front + Panel Admin (PHP + MySQL)**

Proyecto tipo **“tienda + panel”** para gestionar **reservas de campos** (Fútbol 7 y Fútbol sala).  
Incluye **catálogo**, **detalle con disponibilidad**, **carrito en sesión**, **confirmación de reservas**, **panel CRUD**, **estados**, **SMTP** y **mantenimiento con backups + logs**.

---

## ✅ Qué incluye

### 🛒 Front (cliente)
- Catálogo de campos (Fútbol 7 / Fútbol sala)
- Detalle del campo con selección de:
  - **Día**
  - **Hora**
  - **Duración (1 o 2 horas)**
- **Disponibilidad**:
  - Deshabilita horas ya ocupadas según `lineareserva`
  - Si una reserva es de **2 horas**, marca ocupada también la **hora siguiente**
- Carrito en sesión (`$_SESSION["carrito"]`)
- Confirmación final con **transacción**:
  - Revalida disponibilidad (anti doble-reserva)
  - Inserta **cliente → reserva → lineareserva**

### 🛠️ Back (admin)
- Login (tabla `usuarios`)
- Panel por secciones:
  - **Reservas**: listado con JOIN + ver / editar / crear
  - **Clientes**: alta + listado
  - **Campos**: listado simple (lectura)
  - **Mantenimiento**:
    - Crear **backup .sql** desde PHP (sin mysqldump)
    - Listar / descargar / eliminar backups
    - Logs en tabla `mantenimiento_log` (auto-creada)

### ✉️ Estados + SMTP
- Estado de reserva (`reserva.estado`):
  - `pendiente` / `confirmada` / `cancelada`
- Acciones:
  - **Confirmar** → envía email por SMTP (STARTTLS) y *solo si se envía* cambia a `confirmada`
  - **Cancelar** → cambia a `cancelada`
  - **Eliminar definitivo** → solo si está `cancelada` (borra líneas y cabecera)

---

## 📁 Estructura

```

/
├─ front/
│  ├─ index.php
│  ├─ css/
│  │  └─ estilo.css
│  ├─ img/
│  │  ├─ campo.png
│  │  ├─ campo_sala.png
│  │  └─ heroe.png
│  └─ inc/
│     ├─ catalogo.php
│     ├─ campo.php
│     ├─ carrito.php
│     └─ finalizacion.php
│
└─ back/
├─ index.php
├─ css/
│  ├─ login.css
│  └─ panel.css
├─ img/
│  ├─ logo.png
│  ├─ fondo.png
│  ├─ email.png
│  └─ password.png
├─ fuentes/
│  └─ (LEMONMILK *.otf)
├─ backups/
│  └─ backup_YYYYMMDD_HHMMSS_reserva_empresa.sql
├─ util/
│  └─ creacion_usuarios.sql
└─ inc/
├─ login.php
├─ smtp.php
├─ tabla.php
├─ tabla_actions.php
├─ tabla_helpers.php
└─ views/
├─ reserva_list.php
├─ reserva_detail.php
├─ reserva_edit.php
├─ reserva_form.php
├─ cliente_list.php
├─ cliente_form.php
├─ campo_list.php
└─ mantenimiento.php

````

---

## 🚀 Puesta en marcha (XAMPP recomendado)

### 1) Copiar en `htdocs`
Ejemplo:
- `C:\xampp\htdocs\Panel-reserva-campo-futbol\`

Rutas:
- **Front:** `http://localhost/Panel-reserva-campo-futbol/front/`
- **Back:**  `http://localhost/Panel-reserva-campo-futbol/back/`

---

## 🗄️ Base de datos

En el proyecto ya tienes backups listos para importar con **estructura + datos**.

### Opción recomendada: Importar backup `.sql`
Importa el archivo más reciente de:
- `back/backups/backup_YYYYMMDD_HHMMSS_reserva_empresa.sql`

**Con phpMyAdmin**
1. Crear BD (si no existe) llamada `reserva_empresa`
2. Importar el `.sql`

**Por consola**
```bash
mysql -u TU_USUARIO -p reserva_empresa < back/backups/backup_YYYYMMDD_HHMMSS_reserva_empresa.sql
````

> Ese backup incluye tablas como: `campo`, `cliente`, `reserva`, `lineareserva`, `usuarios`, `mantenimiento_log`.

---

## 🔐 Credenciales por defecto

### Panel Admin (Login)

* **Usuario:** `piero7ov`
* **Contraseña:** `piero7ov`

> Se guardan como hash (`password_hash`) y se validan con `password_verify()`.

---

## ⚙️ Configuración del proyecto

### 🔧 Config de BD (Back)

Archivo:

* `back/index.php`

Por defecto:

```php
$DB_HOST = "localhost";
$DB_USER = "reserva_empresa";
$DB_PASS = "Reservaempresa123_";
$DB_NAME = "reserva_empresa";
```

### 🔧 Config de BD (Front)

Estos archivos conectan directo con `new mysqli(...)`:

* `front/inc/catalogo.php`
* `front/inc/campo.php`
* `front/inc/carrito.php`
* `front/inc/finalizacion.php`

Si cambias usuario/clave/BD, ajusta **en esos 4 archivos**.

---

## ✉️ SMTP (Confirmación por correo)

El envío SMTP está implementado por sockets y **usa STARTTLS (tipo Gmail 587)**:

* Implementación: `back/inc/smtp.php`
* Configuración: `back/inc/tabla.php`

Variables a configurar:

```php
$SMTP_HOST = "smtp.gmail.com";
$SMTP_PORT = 587;              // STARTTLS
$SMTP_USER = "TU_CORREO@gmail.com";
$SMTP_PASS = "TU_APP_PASSWORD"; // contraseña de aplicación (Gmail con 2FA)
$SMTP_FROM_EMAIL = $SMTP_USER;
$SMTP_FROM_NAME  = "Reservas";
```

✅ Regla importante del panel:

* Si el correo **no se envía**, la reserva **NO** pasa a `confirmada`.

---

## 🧱 Mantenimiento: Backups + Logs

### Backups

En el panel → **Mantenimiento** → **Crear backup ahora**

* Se genera un `.sql` en: `back/backups/`
* El backup se genera **desde PHP** (sin mysqldump):

  * Lee estructura con `SHOW CREATE TABLE`
  * Exporta datos con `INSERT INTO ...`
* Retención automática: mantiene **los 10 más recientes**

### Logs

* Se registra actividad en `mantenimiento_log`
* Se crea automáticamente si no existe (desde la vista/acciones de mantenimiento)

---

## 🧭 Cómo se usa (flujo rápido)

### Front

1. Entrar al catálogo
2. Seleccionar campo → elegir fecha/hora/duración → **Añadir al carrito**
3. En carrito → completar datos del cliente → **Finalizar**
4. `finalizacion.php`:

   * revalida disponibilidad
   * inserta `cliente`, `reserva`, `lineareserva` en transacción

> Horario manejado en el front: **09:00 a 21:00**
> Duración máxima: **2 horas** (no permite empezar 2h a las 21:00)

### Back

1. Login
2. Reservas:

   * Ver detalle / editar / crear
   * Confirmar (SMTP) / cancelar / eliminar (solo si cancelada)
3. Mantenimiento:

   * Backups + logs

---

## 📄 Licencia

Uso educativo / demostración.

---

**Autor:** Piero Olivares — **PieroDev**
(c) 2026
