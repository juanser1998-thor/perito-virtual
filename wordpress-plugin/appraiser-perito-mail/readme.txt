=== Appraiser Perito Virtual - Correo PDF ===
Contributors: juansebastian
Tags: correo, pdf, formulario, avaluo
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Genera y envía por correo el concepto preliminar de Perito Virtual.

== Instalación ==

1. En WordPress abre Plugins > Añadir plugin > Subir plugin.
2. Selecciona appraiser-perito-mail.zip.
3. Activa el plugin.
4. Abre Ajustes > Perito Virtual - Correo.
5. Copia la URL del endpoint que aparece en esa pantalla.
6. Añade el origen donde se publicará la nueva página, por ejemplo:
   https://perito.tudominio.com
7. Guarda la configuración.
8. Pega la URL del endpoint en perito-integrado/config.js del proyecto web.

== Configuración de correo ==

Deja vacío el campo "Correo del remitente" durante la primera prueba. De esta
forma WordPress conserva el remitente que ya esté configurado en el alojamiento.

Si WordPress no logra entregar correos, revisa primero si otros formularios del
sitio pueden enviarlos. El plugin utiliza wp_mail() y respeta cualquier plugin
SMTP que ya esté configurado en WordPress.

== Seguridad y privacidad ==

* Valida todos los campos en el servidor.
* Repite el cálculo en el servidor; no confía en el valor enviado por el navegador.
* Limita los envíos por hora y por dirección de red.
* Restringe las solicitudes a los orígenes configurados.
* No guarda datos personales ni conceptos en la base de datos.
* El PDF temporal se elimina inmediatamente después de entregarlo al sistema de correo.

== Alcance de la versión 1.0.0 ==

El PDF incluye datos, estimación y alcance del concepto. Las fotografías no se
adjuntan en esta primera versión para evitar superar los límites del correo y del
alojamiento. El formulario conserva la selección fotográfica para una integración
posterior con almacenamiento controlado.

== Changelog ==

= 1.0.0 =
* Primera versión instalable.
