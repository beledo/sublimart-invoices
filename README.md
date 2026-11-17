# Sublimart · Argento 100x100 · El Merchandising de tu club

Aplicación PHP/MySQL basada en Helher House. Elegí una marca en el selector y entrá con su contraseña. Cada marca tiene su propia sesión, base MySQL, clientes, empresa, numeración, historial, papelera y carpeta privada de PDF. Podés usar varias marcas en pestañas distintas.

Los tres nombres y los logos adjuntos están precargados. Los datos fiscales, de contacto y bancarios quedan vacíos para completarlos con los datos reales de cada empresa. La instalación no incluye clientes de ejemplo.

## Qué incluye

- Elegir marca al entrar y cambiarla desde la cabecera.
- Conservar el borrador de cada marca al cambiar dentro de la misma pestaña y sesión. Caducan a las ocho horas y no se comparten entre dispositivos; guardá el documento para conservarlo en el historial.
- Alta y edición de clientes, búsqueda por DNI/NIF/CIF/NIE o nombre. Para guardar un cliente basta con nombre e identificación; la dirección y el contacto son opcionales.
- Presupuestos sencillos con logo, cliente, productos/servicios, cantidades, precios, IVA, total y observaciones/condiciones opcionales. Sin textos de obras ni cláusulas de Helher.
- Vista previa, PDF descargable, duplicar, convertir presupuesto en factura, historial, papelera recuperable durante 30 días y exportaciones trimestrales ZIP/CSV de cada marca.
- IVA seleccionable 0 %, 4 %, 10 % o 21 %, con los mismos cálculos y redondeos en servidor y PDF. Para las facturas se requieren datos fiscales y domicilio completos del emisor y el cliente.
- Diseño adaptable a ordenador, tableta y móvil. Los PDF se generan localmente en el navegador, con bibliotecas incluidas, sin servicios de pago ni API externas.

## Instalar en Hostinger

Código de `beledo/sublimart-invoices`. Desplegá **el contenido de este repositorio** en la raíz de un sitio o subdominio PHP, normalmente `public_html`. También podés conectar este repositorio desde Git en Hostinger y seleccionar la rama `main`.

1. Activá HTTPS y PHP 8.3 o superior de 64 bits, con `pdo_mysql` y `zip`.
2. Subí el contenido del repositorio (o del ZIP de instalación) a `public_html`, incluyendo `.htaccess` y `.user.ini`. No hay compilación ni `npm install`.
3. Creá **tres bases MySQL nuevas y vacías**, con un usuario y contraseña para cada una. Es recomendable dar a cada usuario permisos solamente sobre su base. Conservá los nombres completos de Hostinger, incluido su prefijo `u123…`.
4. Por SSH, desde la carpeta de la aplicación, ejecutá:

   ```sh
   php app/preparar-instalacion.php
   ```

   Muestra una clave de instalación de un solo uso y crea el almacenamiento privado fuera de la raíz web.
5. Abrí `https://tu-dominio/instalar.php`, ingresá la clave y los datos MySQL de las tres marcas. Elegí una contraseña de acceso de al menos 8 caracteres. El instalador la asigna como contraseña inicial de cada marca, comprueba que las tres bases sean diferentes y crea las tablas y los perfiles.
6. Entrá a cada marca y completá **Datos de [marca]** con los datos fiscales, de contacto y bancarios reales. Podés crear presupuestos con el nombre precargado; para emitir facturas se pide completar la ficha fiscal.

La clave temporal se elimina al terminar y el instalador se bloquea cuando ya existe una configuración. Si falla una conexión, no se crean tablas hasta haber verificado las tres. Si se interrumpe después de crear tablas, se puede reintentar con las mismas bases de esa marca.

### Instalación en una subcarpeta

Si se sirve desde una subcarpeta de otro sitio, fijá `SUBLIMART_DATA_DIR` al **mismo directorio privado absoluto fuera de toda la raíz web** en PHP y SSH. No compartas ese directorio con Helher ni con otra instalación. No subas credenciales, sesiones, PDF privados ni copias de bases a GitHub.

## Datos y actualizaciones

Al actualizar una instalación anterior, elegí la marca y volvé a entrar con tu contraseña anterior. Desde **Cambiar contraseña** podés cambiarla para cada marca. Si cambiás una contraseña, las sesiones de esa marca vuelven a pedir acceso.

Los datos no se guardan en el repositorio. Respaldá las tres bases y el directorio privado, que contiene la configuración, las sesiones y `brands/[marca]/pdf/`. Actualizar el código no reemplaza esas bases. Los documentos ya guardados conservan su ficha de empresa y su PDF.

Las tablas se llaman igual en cada base, pero son tres bases físicas distintas. El instalador comprueba tanto los nombres configurados como la identidad que devuelve MySQL para evitar asignar la misma base a dos marcas.

La limpieza opcional por cron se ejecuta con `php app/limpiar-papelera.php`; recorre las tres marcas y elimina documentos con recuperación vencida. También se limpian al abrir cada marca.

## Validación de desarrollo

Pruebas realizadas con PHP 8.4 y MariaDB 11.8 en una instalación desechable. Los registros y las credenciales de esas pruebas no están incluidos en la aplicación.

```sh
node tests/pdf.test.cjs
```

Comprueba logos, generación de los tres PDF, importes, los cuatro tipos de IVA y documentos extensos.

`tests/integration.py` prueba instalación, aislamiento entre tres bases, búsquedas, duplicados, CSRF, revisión de documentos, numeración, descargas y papelera. Se ejecuta **solo en un entorno desechable**, con tres bases vacías, usando `SM_TEST_BASE_URL` y un archivo JSON privado indicado por `SM_TEST_CONFIG`. No lo ejecutes contra datos reales.

`tests/migration.test.php` comprueba que, si una instalación parcial se reintenta con otra base vacía, el marcador de la base anterior no omita la creación de tablas. Requiere `SM_TEST_RETRY_DB` y permisos sobre esa base desechable.
