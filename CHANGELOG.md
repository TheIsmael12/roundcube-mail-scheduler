# Changelog

## 1.0.0 — 2026-11-04

Primera versión.

### Novedades

- **Programar envío** desde el redactor: sugerencias rápidas (dentro de una hora, esta tarde, mañana por la mañana, el lunes), fecha y hora a medida y zona horaria del usuario.
- **Envíos periódicos**: cada día, días laborables, cada semana o cada mes, con fecha de fin opcional. Mantienen la hora local aunque cambie el horario de verano.
- **Firma a elegir** al programar: cualquiera de las firmas guardadas en las identidades, o ninguna.
- **Envíos programados** en Ajustes: pendientes, enviados e historial; reprogramar, enviar ya, volver al redactor o cancelar; vista previa y adjuntos.
- **Respuestas automáticas** (fuera de la oficina, vacaciones…): periodo, identidad, asunto y texto HTML con variables, firma a elegir, una respuesta por remitente cada N días, filtros de dominios y exclusiones, y protección anti-bucle según RFC 3834.
- **Worker para cron** (`bin/worker.php`) con bloqueo, reintentos con espera creciente y copia en Enviados.
- Estilos para **Elastic**, **NextAura**, **Imora** y **Salud en Ruta**, en modo claro y oscuro.
- Español (España) e inglés.
- Compatible con Roundcube 1.5, 1.6 y 1.7 (probado en 1.5.10, 1.6.11 y 1.7.5) y con MySQL/MariaDB, PostgreSQL y SQLite.
