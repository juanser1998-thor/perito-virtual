Perito Virtual - Valu
=====================

Aplicación web local para orientar el valor comercial de un inmueble. Incluye
la bienvenida, la introducción narrada, la guía interactiva y el formulario.

EJECUCIÓN LOCAL
---------------

1. Abre una terminal dentro de esta carpeta.
2. Ejecuta uno de estos comandos:

   Windows con Python:
   py -m http.server 4173

   Otras instalaciones de Python:
   python -m http.server 4173

3. Abre http://localhost:4173/perito_virtual.html

No abras el archivo con doble clic: el navegador limita algunas funciones de
audio, almacenamiento y acceso al iframe cuando se usa el protocolo file://.

ESTRUCTURA
----------

- perito_virtual.html: experiencia principal.
- css/perito_virtual.css: estilos de bienvenida, introducción y guía.
- perito-integrado/: formulario y cálculo preliminar.
- wordpress-plugin/: plugin instalable para generar y enviar el PDF.
- audio/: narraciones locales.
- images/: poses de Valu.

ALCANCE ACTUAL
--------------

- Todos los recursos visuales y sonoros son locales.
- El borrador se conserva únicamente en el navegador del dispositivo.
- El resultado se puede guardar como PDF mediante la opción de impresión.
- El cálculo es una orientación preliminar y no reemplaza un avalúo certificado.
- El envío automático por correo requiere conectar un servicio de servidor antes
  de publicar el proyecto. El plugin de WordPress incluido proporciona ese servicio.

PLUGIN DE CORREO
----------------

El archivo instalable se genera como:
wordpress-plugin/appraiser-perito-mail.zip

Después de instalarlo, copia la URL mostrada en Ajustes > Perito Virtual - Correo
y pégala en perito-integrado/config.js.
