<div align="center">

# Mail Scheduler para Roundcube

**Programa tus correos y deja respuestas automáticas de fuera de la oficina, con la firma que quieras.**

[![CI](https://github.com/TheIsmael12/roundcube-mail-scheduler/actions/workflows/ci.yml/badge.svg)](https://github.com/TheIsmael12/roundcube-mail-scheduler/actions/workflows/ci.yml)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-37beff?style=flat-square)](LICENSE)
[![Roundcube](https://img.shields.io/badge/Roundcube-1.5%20–%201.7.5-2f3a3f?style=flat-square)](https://roundcube.net/)
[![PHP](https://img.shields.io/badge/PHP-7.3%20–%208.4-777bb4?style=flat-square)](https://www.php.net/)

</div>

---

## Capturas

Roundcube 1.7.5 con el skin **Elastic** (el estándar de Roundcube).

### Programar desde el redactor

| Modo claro | Modo oscuro |
|---|---|
| ![Diálogo «Programar envío»](docs/capturas/redactar-programar-light.png) | ![Diálogo «Programar envío», modo oscuro](docs/capturas/redactar-programar-dark.png) |

### Envíos programados (Ajustes)

| Modo claro | Modo oscuro |
|---|---|
| ![Lista de envíos programados](docs/capturas/programados-light.png) | ![Lista de envíos programados, modo oscuro](docs/capturas/programados-dark.png) |
| ![Historial de enviados](docs/capturas/enviados-light.png) | ![Historial de enviados, modo oscuro](docs/capturas/enviados-dark.png) |

### Respuestas automáticas (fuera de la oficina)

| Modo claro | Modo oscuro |
|---|---|
| ![Respuesta automática](docs/capturas/respuestas-light.png) | ![Respuesta automática, modo oscuro](docs/capturas/respuestas-dark.png) |

<details>
<summary>Formulario completo, repetición, móvil y menú de Ajustes</summary>

| | |
|---|---|
| ![Formulario completo](docs/capturas/respuestas-formulario-light.png) | ![Envío periódico](docs/capturas/redactar-repetir-light.png) |
| ![Móvil](docs/capturas/movil-light.png) | ![Menú de Ajustes](docs/capturas/ajustes-light.png) |

</details>

<details>
<summary>Con los temas NextAura, Imora y Salud en Ruta</summary>

| NextAura | Imora | Salud en Ruta |
|---|---|---|
| ![NextAura](docs/capturas/temas/nextaura-redactar.png) | ![Imora](docs/capturas/temas/imora-redactar.png) | ![Salud en Ruta](docs/capturas/temas/saludenruta-redactar.png) |
| ![NextAura](docs/capturas/temas/nextaura-programados.png) | ![Imora](docs/capturas/temas/imora-programados.png) | ![Salud en Ruta](docs/capturas/temas/saludenruta-programados.png) |

</details>

---

## Qué hace

### Programar envío

- Botón **Programar** junto a **Enviar** y en la barra del redactor.
- **Sugerencias rápidas** calculadas en tu zona horaria: dentro de una hora, esta tarde, mañana por la mañana y el lunes por la mañana.
- **Fecha y hora a medida**, con validación (no deja programar en el pasado ni más allá del límite configurado).
- **Envíos periódicos**: cada día, días laborables, cada semana o cada mes, con fecha de fin opcional. Respetan la hora local aunque cambie el horario de verano, y «cada mes el día 31» cae el último día de los meses más cortos.
- **Firma a elegir**: mantener la actual, quitarla o usar **cualquiera de las firmas guardadas en tus identidades**, con vista previa.
- El mensaje se guarda **tal cual lo compones**, con adjuntos, CC, CCO, prioridad y confirmación de entrega. Al enviarse se guarda una copia en **Enviados** y se marca el original como respondido o reenviado.

### Envíos programados (Ajustes)

- Filtros **Pendientes**, **Enviados** y **Todos**, con contador.
- Detalle con remitente, destinatarios, adjuntos, vista previa del texto y estado: pendiente, reintentando, enviado o error.
- **Reprogramar** (fecha, hora y repetición), **Enviar ya**, **Editar mensaje** (vuelve a Borradores y lo abre en el redactor) y **Cancelar envío**.

### Respuestas automáticas

Para avisos de fuera de la oficina, vacaciones, bajas, cambios de horario…

- **Periodo** con inicio y fin opcionales, o activación manual.
- **Responder como** cualquiera de tus identidades.
- Asunto y texto en **HTML** (editor de Roundcube) con variables: `{subject}`, `{sender}`, `{since}` y `{until}`.
- **Firma a elegir** entre todas las de tus identidades.
- **Una respuesta por remitente cada N días.**
- Responder solo si tu dirección está en **Para o CC**, ignorar **listas y boletines**, limitar a ciertos **dominios** y excluir direcciones o dominios (`*@proveedor.com`).
- **Anti-bucle (RFC 3834)**: no responde nunca a mensajes con `Auto-Submitted`, a `no-reply`, `mailer-daemon` y similares, ni a ti mismo. Las respuestas llevan `Auto-Submitted: auto-replied`, `In-Reply-To` y `References`.
- Solo responde a los correos que lleguen **después** de activarla.

> Roundcube trae el plugin `managesieve`, que también tiene respuestas de vacaciones, pero no permite elegir firma, usar HTML ni programar varias reglas, y necesita un servidor con Sieve. Este plugin funciona con cualquier servidor IMAP/SMTP. Si ya usas Sieve, puedes desactivar esta parte con `mail_scheduler_autoreply = false`.

---

## Requisitos

- Roundcube **1.5, 1.6 o 1.7** (probado en 1.5.10, 1.6.11 y 1.7.5).
- PHP 7.3 o superior (8.1+ con Roundcube 1.7). La extensión `intl` es opcional y da fechas mejor localizadas.
- MySQL/MariaDB, PostgreSQL o SQLite.
- Poder ejecutar una tarea **cron** cada minuto.

## Instalación

### Con Composer

```bash
cd /var/www/roundcube
composer require theismael12/mail_scheduler
```

### Manual

```bash
cd /var/www/roundcube/plugins
git clone https://github.com/TheIsmael12/roundcube-mail-scheduler.git mail_scheduler
```

Y añádelo a `config/config.inc.php`:

```php
$config['plugins'] = ['mail_scheduler', /* … */];
```

### Base de datos

Las tablas se crean solas la primera vez (`mail_scheduler_auto_install`). Si prefieres hacerlo a mano:

```bash
bin/initdb.sh --dir=plugins/mail_scheduler/SQL
```

### Cron

El envío real lo hace `bin/worker.php`. Ejecútalo **cada minuto** con el mismo usuario del servidor web:

```cron
* * * * * www-data php /var/www/roundcube/plugins/mail_scheduler/bin/worker.php
```

Opciones: `--verbose` para ver lo que hace y `--only=scheduled|autoreply` para lanzar solo una parte. Usa un bloqueo, así que nunca se solapan dos ejecuciones.

---

## Configuración

Copia `config.inc.php.dist` como `config.inc.php` y cambia lo que necesites:

| Opción | Por defecto | Para qué sirve |
|---|---|---|
| `mail_scheduler_auto_install` | `true` | Crear las tablas automáticamente |
| `mail_scheduler_store_credentials` | `true` | Guardar las credenciales cifradas para el cron (ver abajo) |
| `mail_scheduler_min_delay` | `60` | Antelación mínima en segundos |
| `mail_scheduler_max_days` | `365` | Máximo de días hacia el futuro (0 = sin límite) |
| `mail_scheduler_max_size` | `25 MB` | Tamaño máximo del mensaje programado |
| `mail_scheduler_morning_hour` / `_afternoon_hour` | `8` / `15` | Horas de las sugerencias |
| `mail_scheduler_max_attempts` | `5` | Reintentos ante errores temporales (5, 15, 60, 180 y 360 min) |
| `mail_scheduler_keep_days` | `30` | Días que se guarda el historial de enviados |
| `mail_scheduler_autoreply` | `true` | Activar las respuestas automáticas |
| `mail_scheduler_autoreply_interval` | `4` | Días por defecto entre respuestas al mismo remitente |
| `mail_scheduler_debug` | `false` | Registrar la actividad en `logs/mail_scheduler` |

### Credenciales y seguridad

Para enviar en nombre del usuario cuando no está conectado, el plugin guarda su usuario y su contraseña **cifrados con la `des_key` de Roundcube**, igual que en la sesión. Se actualizan en cada inicio de sesión (por si cambia la contraseña) y **se borran solas** cuando el usuario ya no tiene envíos pendientes ni respuestas automáticas activas.

Si no quieres guardarlas (`mail_scheduler_store_credentials = false`), el SMTP debe aceptar el envío sin la contraseña del usuario (por ejemplo, `smtp_user`/`smtp_pass` fijos o un relay local). En ese caso no habrá copia en Enviados ni respuestas automáticas, porque necesitan acceder al buzón.

---

## Temas (skins)

El plugin usa los componentes de Roundcube (listas, marcos de contenido, `propform`, diálogos, editor HTML, botones e iconos de Elastic) y hereda los colores del tema, con modo claro y oscuro.

Incluye estilos para:

| Skin | Tema |
|---|---|
| `elastic` | El estándar de Roundcube |
| `nextaura` | [NextAura](https://github.com/TheIsmael12/nextaura-roundcube) |
| `imora` | [Imora](https://github.com/TheIsmael12/imora-roundcube) |
| `saludenruta` | [Salud en Ruta](https://github.com/TheIsmael12/serad-roundcube) |

### Añadir otro skin derivado de Elastic

1. Copia su `styles/colors.less` en `build/palettes/<skin>/colors.less`.
2. Ejecuta `npm install && npm run build`.

Se generan `skins/<skin>/styles/mail_scheduler.css` y las plantillas.

---

## Desarrollo

```bash
# Entorno con Roundcube 1.7.5 (puerto 8312), 1.6.11 (8311), 1.5.10 (8310), GreenMail y los workers
docker compose -f docker/docker-compose.yml up -d
# Usuario: cualquier dirección @example.com · Contraseña: cualquiera

npm install
npm run build      # compila los estilos de todos los skins
npm run check      # comprueba que el CSS y las plantillas están al día

# Pruebas unitarias dentro de Roundcube
docker exec mail-scheduler-rc17-1 php /var/www/html/plugins/mail_scheduler/tests/run.php
```

### Cómo funciona por dentro

1. Al pulsar **Programar envío**, el redactor añade la fecha al formulario y lanza el envío normal de Roundcube, así el mensaje se compone exactamente igual (adjuntos, firma, codificación…).
2. El hook `message_before_send` lo intercepta: guarda el MIME en la base de datos en vez de entregarlo, limpia el borrador y cierra el redactor.
3. `bin/worker.php` entrega por SMTP los mensajes vencidos (sin la cabecera `Bcc`), guarda la copia en Enviados y calcula la siguiente fecha si el envío es periódico.
4. Para las respuestas automáticas, el worker lee por IMAP los correos nuevos de la bandeja de entrada (sin marcarlos como leídos) y responde según las reglas.

---

## Licencia

[GNU GPL v3 o posterior](LICENSE), como Roundcube.

Los estilos siguen las convenciones del skin Elastic (© The Roundcube Dev Team, CC BY-SA 3.0); las paletas de `build/palettes` proceden de cada tema.
