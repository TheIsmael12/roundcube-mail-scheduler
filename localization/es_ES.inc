<?php

/**
 * Mail Scheduler · español (España)
 */

$labels = [];

// Redactor
$labels['schedule']          = 'Programar';
$labels['schedulesend']      = 'Programar envío';
$labels['scheduletitle']     = 'Elegir cuándo se enviará este mensaje';
$labels['schedulebutton']    = 'Programar envío';
$labels['quickpicks']        = 'Sugerencias';
$labels['quick_in1h']        = 'Dentro de una hora';
$labels['quick_afternoon']   = 'Esta tarde';
$labels['quick_tomorrow']    = 'Mañana por la mañana';
$labels['quick_tomorrowpm']  = 'Mañana por la tarde';
$labels['quick_monday']      = 'El lunes por la mañana';
$labels['customdatetime']    = 'Fecha y hora personalizadas';
$labels['date']              = 'Fecha';
$labels['time']              = 'Hora';
$labels['repeat']            = 'Repetir';
$labels['rec_none']          = 'No se repite';
$labels['rec_daily']         = 'Cada día';
$labels['rec_weekdays']      = 'Días laborables (de lunes a viernes)';
$labels['rec_weekly']        = 'Cada semana';
$labels['rec_monthly']       = 'Cada mes';
$labels['rec_short_daily']    = 'Diario';
$labels['rec_short_weekdays'] = 'Laborables';
$labels['rec_short_weekly']   = 'Semanal';
$labels['rec_short_monthly']  = 'Mensual';
$labels['repeatuntil']       = 'Repetir hasta';
$labels['repeatuntilhint']   = 'Opcional. Déjalo vacío para que se repita indefinidamente.';
$labels['signature']         = 'Firma';
$labels['sig_keep']          = 'Mantener la firma actual';
$labels['sig_none']          = 'Sin firma';
$labels['sig_of']            = 'Firma de $name';
$labels['sig_preview']       = 'Vista previa de la firma';
$labels['nosignatures']      = 'No tienes firmas guardadas. Puedes crearlas en Ajustes › Identidades.';
$labels['willbesent']        = 'Se enviará el $date';
$labels['willrepeat']        = 'Primer envío el $date · $rec';
$labels['timezonehint']      = 'Zona horaria: $tz';
$labels['scheduledfor']      = 'Programado para el $date';
$labels['scheduledok']       = 'Envío programado para el $date.';
$labels['scheduledrecurringok'] = 'Envío periódico programado. El primero saldrá el $date.';

// Errores
$labels['invaliddatetime']   = 'La fecha o la hora no son válidas.';
$labels['datepast']          = 'Elige una fecha y una hora futuras.';
$labels['datetoofar']        = 'Solo se puede programar con un máximo de $n días de antelación.';
$labels['invaliduntil']      = 'La fecha de fin de la repetición no es válida o es anterior al primer envío.';
$labels['toobig']            = 'El mensaje es demasiado grande para programarlo (máximo $size).';
$labels['nocredentials']     = 'No se ha podido preparar el envío en segundo plano. Vuelve a iniciar sesión e inténtalo de nuevo.';
$labels['errorsaving']       = 'No se ha podido guardar el envío programado.';
$labels['notfound']          = 'El elemento ya no existe.';
$labels['notEditable']       = 'Este envío ya no se puede modificar.';

// Ajustes · envíos programados
$labels['scheduled']         = 'Envíos programados';
$labels['scheduledtitle']    = 'Revisa y gestiona tus envíos programados';
$labels['filter_pending']    = 'Pendientes';
$labels['filter_sent']       = 'Enviados';
$labels['filter_all']        = 'Todos';
$labels['listempty_pending'] = 'No tienes envíos programados. Al redactar, usa «Programar» para elegir cuándo sale un mensaje.';
$labels['listempty_sent']    = 'Todavía no se ha enviado ningún mensaje programado.';
$labels['listempty_all']     = 'No hay envíos programados.';
$labels['to']                = 'Para:';
$labels['status_pending']    = 'Pendiente';
$labels['status_retrying']   = 'Reintentando';
$labels['status_sending']    = 'Enviando';
$labels['status_sent']       = 'Enviado';
$labels['status_failed']     = 'Error';
$labels['status_cancelled']  = 'Cancelado';
$labels['sendon']            = 'Fecha de envío';
$labels['schedulefieldset']  = 'Programación';
$labels['preview']           = 'Vista previa';
$labels['emptybody']         = '(Mensaje sin texto)';
$labels['sentcount']         = 'Enviado $n veces';
$labels['sentcount1']        = 'Enviado una vez';
$labels['lastsent']          = 'último envío: $date';
$labels['errorfailed']       = 'No se ha podido enviar.';
$labels['errorretry']        = 'Último intento fallido; se volverá a intentar automáticamente.';
$labels['err_nocredentials'] = 'Faltan las credenciales del usuario. Inicia sesión de nuevo y pulsa «Reintentar».';
$labels['err_interrupted']   = 'El envío se interrumpió y no se sabe si llegó a salir. Revisa la carpeta Enviados antes de reintentarlo.';
$labels['reschedule']        = 'Guardar cambios';
$labels['sendnow']           = 'Enviar ya';
$labels['retry']             = 'Reintentar';
$labels['todraft']           = 'Editar mensaje';
$labels['cancelsend']        = 'Cancelar envío';
$labels['confirmcancel']     = '¿Seguro que quieres cancelar este envío? El mensaje se eliminará.';
$labels['confirmsendnow']    = 'El mensaje se enviará en el próximo minuto. ¿Continuar?';
$labels['confirmtodraft']    = 'El mensaje se moverá a Borradores y se abrirá en el redactor. Tendrás que volver a programarlo. ¿Continuar?';
$labels['rescheduledok']     = 'Envío reprogramado para el $date.';
$labels['sendnowok']         = 'El mensaje saldrá en el próximo minuto.';
$labels['cancelledok']       = 'Envío cancelado.';
$labels['noselection']       = 'Selecciona un envío para ver sus detalles.';

// Ajustes · respuestas automáticas
$labels['autoreplies']       = 'Respuestas automáticas';
$labels['autorepliestitle']  = 'Fuera de la oficina, vacaciones y otras respuestas automáticas';
$labels['arlistempty']       = 'Aún no tienes respuestas automáticas. Pulsa «Crear» para avisar de que estás fuera de la oficina o de vacaciones.';
$labels['newautoreply']      = 'Nueva respuesta automática';
$labels['editautoreply']     = 'Editar respuesta automática';
$labels['deleteautoreply']   = 'Eliminar';
$labels['confirmdeleteautoreply'] = '¿Seguro que quieres eliminar esta respuesta automática?';
$labels['autoreplydeleted']  = 'Respuesta automática eliminada.';
$labels['autoreplyunnamed']  = 'Respuesta automática';
$labels['ar_state_off']      = 'Desactivada';
$labels['ar_state_active']   = 'Activa ahora';
$labels['ar_state_scheduled'] = 'Programada';
$labels['ar_state_ended']    = 'Finalizada';
$labels['period_range']      = 'Del $from al $until';
$labels['period_from']       = 'Desde el $from, sin fecha de fin';
$labels['period_until']      = 'Hasta el $until';
$labels['period_always']     = 'Siempre que esté activada';
$labels['argeneral']         = 'General';
$labels['arperiod']          = 'Periodo';
$labels['armessagefs']       = 'Mensaje';
$labels['aroptions']         = 'Opciones';
$labels['arname']            = 'Nombre';
$labels['arnameplaceholder'] = 'p. ej. Vacaciones de verano';
$labels['arenabled']         = 'Activada';
$labels['arfrom']            = 'Desde';
$labels['arfromhint']        = 'Déjalo vacío para empezar ahora mismo.';
$labels['aruntil']           = 'Hasta';
$labels['aruntilhint']       = 'Déjalo vacío para no poner fecha de fin. Zona horaria: $tz';
$labels['aridentity']        = 'Responder como';
$labels['armessage']         = 'Texto';
$labels['arhtml']            = 'Mensaje en HTML';
$labels['arvarshint']        = 'Puedes usar <code>{subject}</code> (asunto original), <code>{sender}</code> (nombre del remitente), <code>{since}</code> y <code>{until}</code> (fechas del periodo).';
$labels['arinterval']        = 'Responder a cada remitente';
$labels['days']              = 'días como máximo una vez';
$labels['arintervalhint']    = 'Evita enviar la misma respuesta varias veces a la misma persona.';
$labels['ar_only_direct']    = 'Solo correos dirigidos a mí';
$labels['ar_only_direct_hint'] = 'Responde únicamente si tu dirección aparece en Para o CC (no en copias ocultas ni listas).';
$labels['ar_skip_bulk']      = 'Ignorar listas y boletines';
$labels['ar_skip_bulk_hint'] = 'No responde a listas de correo, newsletters, notificaciones ni spam. Los mensajes automáticos y los remitentes «no-reply» se ignoran siempre.';
$labels['ar_save_sent']      = 'Guardar copia en Enviados';
$labels['ar_save_sent_hint'] = 'Guarda en Enviados cada respuesta automática que se envíe.';
$labels['ar_domains']        = 'Solo a estos dominios';
$labels['ar_domains_hint']   = 'Uno por línea. Vacío = responder a cualquier dominio.';
$labels['ar_exclude']        = 'No responder nunca a';
$labels['ar_exclude_hint']   = 'Direcciones o dominios, uno por línea. Admite comodines: *@proveedor.com';
$labels['arcount']           = '$n respuestas enviadas';
$labels['arcount1']          = '1 respuesta enviada';
$labels['aroverlap']         = 'Otra respuesta automática activa coincide con este periodo. Solo debería haber una activa a la vez.';
$labels['arnocredentials']   = 'El administrador ha desactivado el almacenamiento de credenciales: las respuestas automáticas no podrán leer tu bandeja de entrada.';
$labels['defaultarsubject']  = 'Fuera de la oficina: {subject}';
$labels['defaultarbody']     = '<p>Hola, {sender}:</p><p>Gracias por tu mensaje. Estoy fuera de la oficina hasta el {until} y con acceso limitado al correo.</p><p>Te responderé lo antes posible a mi vuelta.</p>';
$labels['invalidfrom']       = 'La fecha de inicio no es válida.';
$labels['untilbeforefrom']   = 'La fecha de fin debe ser posterior a la de inicio.';
$labels['invalididentity']   = 'Elige una identidad válida.';
$labels['emptyarbody']       = 'Escribe el texto de la respuesta.';

// Formatos de fecha (ICU)
$labels['date_pattern_short'] = "EEE d MMM y, HH:mm";
$labels['date_pattern_long']  = "EEEE, d 'de' MMMM 'de' y, 'a las' HH:mm";

$messages = [];
