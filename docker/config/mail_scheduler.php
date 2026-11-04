<?php

// Configuración extra para el entorno de pruebas (docker/docker-compose.yml)
$config['language']                = 'es_ES';
$config['timezone']                = 'Europe/Madrid';
$config['product_name']            = 'Roundcube Webmail';
$config['mail_scheduler_debug']    = true;
$config['mail_scheduler_min_delay'] = 60;

// Una sola sesión de pruebas sin caducar mientras se hacen capturas
$config['session_lifetime'] = 600;
