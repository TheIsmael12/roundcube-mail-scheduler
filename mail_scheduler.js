/**
 * Mail Scheduler · cliente
 *
 * Diálogo «Programar envío» del redactor y pantallas de Ajustes
 * (envíos programados y respuestas automáticas).
 *
 * @licstart  The following is the entire license notice for the
 * JavaScript code in this file.
 *
 * Copyright (C) TheIsmael12
 *
 * The JavaScript code in this page is free software: you can redistribute it
 * and/or modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation, either version 3 of
 * the License, or (at your option) any later version.
 *
 * @licend  The above is the entire license notice
 * for the JavaScript code in this file.
 */

(function ($) {
    'use strict';

    if (!window.rcmail) {
        return;
    }

    var rc = window.rcmail;

    function label(name, vars) {
        var text = rc.get_label(name, 'mail_scheduler');

        if (vars) {
            $.each(vars, function (k, v) {
                text = text.split('$' + k).join(v);
            });
        }

        return text;
    }

    function pad(n) {
        return (n < 10 ? '0' : '') + n;
    }

    /* =====================================================================
     * Fechas «de pared» (en la zona horaria del usuario, no la del navegador)
     * ===================================================================== */

    // Convierte 'YYYY-MM-DD' + 'HH:MM' en milisegundos UTC «ficticios»: sirve
    // para comparar y formatear en la zona del usuario sin conversiones.
    function wall(date, time) {
        var d = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date || ''),
            t = /^(\d{1,2}):(\d{2})$/.exec(time || '');

        if (!d || !t) {
            return null;
        }

        var ms = Date.UTC(+d[1], +d[2] - 1, +d[3], +t[1], +t[2]),
            check = new Date(ms);

        // descarta fechas imposibles (31 de febrero…)
        if (check.getUTCDate() != +d[3] || check.getUTCMonth() != +d[2] - 1) {
            return null;
        }

        return ms;
    }

    function format_wall(ms, opts) {
        var env = rc.env.mail_scheduler || {},
            locale = env.locale || rc.env.locale || 'en-US';

        try {
            return new Intl.DateTimeFormat(locale, $.extend({ timeZone: 'UTC' }, opts)).format(new Date(ms));
        } catch (e) {
            return new Date(ms).toISOString().replace('T', ' ').substr(0, 16);
        }
    }

    function long_date(ms) {
        return format_wall(ms, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    function short_date(ms) {
        return format_wall(ms, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    }

    // «Ahora» en la zona del usuario, como hora de pared
    function wall_now() {
        var env = rc.env.mail_scheduler || {},
            p = (env.now_local || '').split(' ');

        if (!rc.env.ms_loaded_at) {
            rc.env.ms_loaded_at = Date.now();
        }

        var base = wall(p[0], p[1]);

        return base === null ? null : base + (Date.now() - rc.env.ms_loaded_at);
    }

    function iso_date(ms) {
        var d = new Date(ms);
        return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
    }

    /* =====================================================================
     * Redactor
     * ===================================================================== */

    function compose_init() {
        rc.env.ms_loaded_at = Date.now();

        // Sin esto Roundcube trataría el comando como «salir del redactor» y
        // preguntaría si descartar los cambios.
        if (rc.env.compose_commands && $.inArray('plugin.mail_scheduler-compose', rc.env.compose_commands) < 0) {
            rc.env.compose_commands.push('plugin.mail_scheduler-compose');
        }

        rc.register_command('plugin.mail_scheduler-compose', function (props) {
            if (!(props && props.nocheck) && !rc.check_compose_input('plugin.mail_scheduler-compose')) {
                return false;
            }

            schedule_dialog();
        }, true);

        // Botón «Programar» junto a «Enviar»
        var send_btn = $('.formbuttons .send').first();

        if (send_btn.length && !$('.ms-schedule-btn').length) {
            $('<button type="button" class="btn btn-secondary schedule ms-schedule-btn">')
                .attr('title', label('scheduletitle'))
                .append($('<span class="inner">').text(label('schedule')))
                .on('click', function () {
                    rc.command('plugin.mail_scheduler-compose', {}, this);
                    return false;
                })
                .insertAfter(send_btn);
        }
    }

    function schedule_dialog() {
        var env = rc.env.mail_scheduler || {},
            quick = env.quick || [],
            selected = null,
            dialog,
            content = $('<div class="ms-dialog">'),
            now_ms = wall_now(),
            tomorrow = iso_date((now_ms || Date.now()) + 86400000);

        // --- Sugerencias ---
        var grid = $('<div class="ms-quick" role="radiogroup">').attr('aria-label', label('quickpicks'));

        $.each(quick, function (i, q) {
            var ms = wall(q.date, q.time);

            $('<button type="button" class="ms-quick-item" role="radio" aria-checked="false">')
                .data('date', q.date).data('time', q.time)
                .append($('<span class="ms-quick-icon">').addClass('ms-icon-' + q.id))
                .append($('<span class="ms-quick-text">')
                    .append($('<span class="ms-quick-label">').text(label('quick_' + q.id)))
                    .append($('<span class="ms-quick-detail">').text(short_date(ms))))
                .on('click', function () {
                    pick($(this).data('date'), $(this).data('time'), this);
                })
                .appendTo(grid);
        });

        content.append($('<div class="ms-section-title">').text(label('quickpicks')), grid);

        // --- Fecha y hora personalizadas ---
        var date_in = $('<input type="date" class="form-control" id="ms-date" required>').attr('min', now_ms ? iso_date(now_ms) : ''),
            time_in = $('<input type="time" class="form-control" id="ms-time" required step="300">');

        if (env.max_days && now_ms) {
            date_in.attr('max', iso_date(now_ms + env.max_days * 86400000));
        }

        date_in.val(tomorrow);
        time_in.val(pad(env.morning || 8) + ':00');

        content.append(
            $('<div class="ms-section-title">').text(label('customdatetime')),
            $('<div class="ms-datetime">')
                .append($('<div class="ms-field">').append($('<label for="ms-date" class="ms-label">').text(label('date')), date_in))
                .append($('<div class="ms-field">').append($('<label for="ms-time" class="ms-label">').text(label('time')), time_in))
        );

        // --- Repetición ---
        var rec = $('<select class="custom-select" id="ms-recurrence">'),
            until_in = $('<input type="date" class="form-control" id="ms-until">'),
            until_row = $('<div class="ms-row ms-until-row hidden">');

        $.each(['none', 'daily', 'weekdays', 'weekly', 'monthly'], function (i, r) {
            rec.append($('<option>').val(r).text(label('rec_' + r)));
        });

        until_row.append($('<div class="ms-field">').append($('<label for="ms-until" class="ms-label">').text(label('repeatuntil')), until_in))
            .append($('<div class="form-text ms-hint">').text(label('repeatuntilhint')));

        content.append(
            $('<div class="ms-row ms-grid-2">')
                .append($('<div class="ms-field">').append($('<label for="ms-recurrence" class="ms-label">').text(label('repeat')), rec))
                .append(signature_field()),
            until_row
        );

        // --- Resumen ---
        var summary = $('<div class="ms-summary-line" aria-live="polite">'),
            error = $('<div class="ms-dialog-error hidden" role="alert">');

        content.append(summary, error,
            $('<div class="form-text ms-hint ms-tz">').text(label('timezonehint', { tz: env.timezone || 'UTC' })));

        function pick(date, time, btn) {
            date_in.val(date);
            time_in.val(time);
            grid.find('.ms-quick-item').removeClass('selected').attr('aria-checked', 'false');
            if (btn) {
                $(btn).addClass('selected').attr('aria-checked', 'true');
            }
            update();
        }

        function current() {
            return wall(date_in.val(), time_in.val());
        }

        function update() {
            var ms = current(),
                r = rec.val();

            until_row.toggleClass('hidden', r == 'none');
            error.addClass('hidden');

            // Etiquetas de repetición con el día concreto
            if (ms !== null) {
                rec.find('option[value=weekly]').text(label('rec_weekly') + ' · ' + format_wall(ms, { weekday: 'long' }));
                rec.find('option[value=monthly]').text(label('rec_monthly') + ' · ' + format_wall(ms, { day: 'numeric' }));
                until_in.attr('min', iso_date(ms));
            }

            // Si la fecha ya no coincide con una sugerencia, se desmarca
            grid.find('.ms-quick-item').each(function () {
                var on = $(this).data('date') == date_in.val() && $(this).data('time') == time_in.val();
                $(this).toggleClass('selected', on).attr('aria-checked', on ? 'true' : 'false');
            });

            if (ms === null) {
                summary.removeClass('ok').text(label('invaliddatetime'));
                return;
            }

            var text = r == 'none'
                ? label('willbesent', { date: long_date(ms) })
                : label('willrepeat', { date: long_date(ms), rec: rec.find('option:selected').text() });

            summary.addClass('ok').text(text);
        }

        function validate() {
            var ms = current(),
                min = (env.min_delay || 60) * 1000 - 30000,
                now = wall_now();

            if (ms === null) {
                return label('invaliddatetime');
            }

            if (now !== null && ms < now + min) {
                return label('datepast');
            }

            if (rec.val() != 'none' && until_in.val() && until_in.val() < date_in.val()) {
                return label('invaliduntil');
            }

            return null;
        }

        function submit() {
            var msg = validate();

            if (msg) {
                error.text(msg).removeClass('hidden');
                return false;
            }

            apply_signature(content.find('#ms-signature').val());

            var form = rc.gui_objects.messageform,
                fields = {
                    _schedule_date: date_in.val(),
                    _schedule_time: time_in.val(),
                    _schedule_recurrence: rec.val(),
                    _schedule_until: rec.val() != 'none' ? until_in.val() : ''
                },
                added = [];

            $.each(fields, function (name, value) {
                added.push($('<input type="hidden">').attr('name', name).val(value).appendTo(form));
            });

            dialog.dialog('close');

            // El envío normal de Roundcube compone el MIME; el servidor lo
            // intercepta y lo guarda en lugar de entregarlo.
            rc.command('send', { nocheck: true });

            // El formulario ya se ha enviado: quitamos los campos para que un
            // «Enviar» posterior (si hubiera error) sea un envío normal.
            $.each(added, function () { this.remove(); });

            return true;
        }

        date_in.add(time_in).add(rec).add(until_in).on('change input', update);
        content.on('keydown', 'input', function (e) {
            if (e.which == 13) {
                submit();
                return false;
            }
        });

        update();

        dialog = rc.show_popup_dialog(content, label('schedulesend'), [
            {
                text: label('schedulebutton'),
                'class': 'mainaction schedule',
                click: function () { submit(); }
            },
            {
                text: rc.get_label('cancel'),
                'class': 'cancel',
                click: function () { dialog.dialog('close'); }
            }
        ], {
            width: 560,
            dialogClass: 'ms-schedule-dialog',
            open: function () {
                setTimeout(function () {
                    var first = grid.find('.ms-quick-item').first();
                    (first.length ? first : date_in).focus();
                }, 10);
            }
        });
    }

    /**
     * Selector de firma: permite adjuntar cualquiera de las firmas guardadas
     * en las identidades al mensaje programado.
     */
    function signature_field() {
        var sigs = rc.env.signatures || {},
            select = $('<select class="custom-select" id="ms-signature">'),
            preview = $('<div class="ms-sig-preview hidden">'),
            wrap = $('<div class="ms-field ms-sig-field">');

        select.append($('<option>').val('').text(label('sig_keep')));
        select.append($('<option>').val('none').text(label('sig_none')));

        $.each(sigs, function (id) {
            var name = $('select[name="_from"] option[value="' + id + '"]').text() || id;

            // «Ana García <ana@example.com>» → «Ana García» (más corto en el desplegable)
            name = name.replace(/\s*<[^>]+>\s*$/, '').replace(/^"(.*)"$/, '$1') || name;
            select.append($('<option>').val(id).text(label('sig_of', { name: name })));
        });

        select.on('change', function () {
            var id = $(this).val(),
                sig = sigs[id];

            if (sig && sig.text) {
                preview.text(sig.text).removeClass('hidden');
            } else {
                preview.text('').addClass('hidden');
            }
        });

        wrap.append($('<label for="ms-signature" class="ms-label">').text(label('signature')), select, preview);

        if ($.isEmptyObject(sigs)) {
            wrap.append($('<div class="form-text ms-hint">').text(label('nosignatures')));
        }

        return wrap;
    }

    function apply_signature(value) {
        if (!value || !rc.editor) {
            return;
        }

        var target = value == 'none' ? '' : value;

        if (target === String(rc.env.identity || '')) {
            return;
        }

        // Reutiliza la lógica de Roundcube que sustituye la firma actual
        rc.editor.change_signature(target, true);
        rc.env.identity = target;
        rc.editor.save();
    }

    /* =====================================================================
     * Ajustes · envíos programados
     * ===================================================================== */

    function frame_window() {
        return rc.get_frame_window(rc.env.contentframe);
    }

    function load_frame(action, params) {
        var win = frame_window();

        if (win) {
            rc.location_href($.extend({ _action: action, _framed: 1 }, params || {}), win, true);
        }
    }

    function scheduled_list_init() {
        if (!rc.gui_objects.scheduledlist) {
            return;
        }

        rc.scheduled_list = new rcube_list_widget(rc.gui_objects.scheduledlist,
            { multiselect: false, draggable: false, keyboard: true });

        rc.scheduled_list
            .addEventListener('select', function (list) {
                var id = list.get_single_selection();

                rc.enable_command('plugin.mail_scheduler-cancel', !!id);

                if (id) {
                    load_frame('plugin.mail_scheduler-edit', { _id: id });
                }
            })
            .addEventListener('keypress', function (list) {
                rc.list_keypress(list, { del: 'plugin.mail_scheduler-cancel' });
            })
            .init()
            .focus();

        rc.register_command('plugin.mail_scheduler-new', function () {
            rc.redirect(rc.url('mail/compose'));
        }, true);

        rc.register_command('plugin.mail_scheduler-cancel', function () {
            var id = rc.scheduled_list.get_single_selection();
            if (id) {
                cancel_message(id);
            }
        }, false);

        rc.mail_scheduler_filter = function (filter) {
            rc.env.mail_scheduler_filter = filter;

            $('#ms-filter .ms-filter-btn').each(function () {
                var on = $(this).data('filter') == filter;
                $(this).toggleClass('active', on).attr('aria-selected', on ? 'true' : 'false');
            });

            $(rc.gui_objects.scheduledlist).attr('data-label-msg', label('listempty_' + filter))
                .data('label-msg', label('listempty_' + filter));

            reload_list();
            return false;
        };
    }

    function reload_list() {
        rc.http_post('plugin.mail_scheduler-action', {
            _act: 'list',
            _filter: rc.env.mail_scheduler_filter || 'pending'
        }, rc.set_busy(true, 'loading'));
    }

    function cancel_message(id, from_frame) {
        var ref = from_frame && parent.rcmail ? parent.rcmail : rc;

        ref.confirm_dialog(label('confirmcancel'), 'mail_scheduler.cancelsend', function () {
            rc.http_post('plugin.mail_scheduler-action', { _act: 'cancel', _id: id }, rc.set_busy(true, 'loading'));
        }, { button_class: 'delete' });
    }

    // Respuesta del servidor con la lista renderizada
    rcube_webmail.prototype.mail_scheduler_list = function (data) {
        var list = this.scheduled_list;

        if (!list) {
            return;
        }

        list.clear(true);
        $(list.tbody).html(data.html || '');
        list.init();

        var counter = $('#ms-filter .ms-filter-btn[data-filter=pending] .ms-count');
        if (data.pending) {
            if (!counter.length) {
                counter = $('<span class="ms-count">').appendTo('#ms-filter .ms-filter-btn[data-filter=pending]');
            }
            counter.text(data.pending);
        } else {
            counter.remove();
        }

        if (this.env.ms_reselect && list.rows[this.env.ms_reselect]) {
            list.select(this.env.ms_reselect);
        } else {
            this.enable_command('plugin.mail_scheduler-cancel', false);
            this.show_contentframe(false);
        }

        this.env.ms_reselect = null;
    };

    // Guardado desde el marco: refresca la lista del padre y el propio detalle
    rcube_webmail.prototype.mail_scheduler_saved = function (data) {
        var p = this.is_framed() && parent.rcmail ? parent.rcmail : this;

        if (p.scheduled_list) {
            p.env.ms_reselect = String(data.id);
            p.http_post('plugin.mail_scheduler-action', { _act: 'list', _filter: p.env.mail_scheduler_filter || 'pending' });
        }

        if (this.is_framed()) {
            setTimeout(function () { window.location.reload(); }, 600);
        }
    };

    rcube_webmail.prototype.mail_scheduler_removed = function (data) {
        var p = this.is_framed() && parent.rcmail ? parent.rcmail : this;

        if (p.scheduled_list) {
            p.scheduled_list.remove_row(String(data.id));
            p.enable_command('plugin.mail_scheduler-cancel', false);
            p.show_contentframe(false);
            p.http_post('plugin.mail_scheduler-action', { _act: 'list', _filter: p.env.mail_scheduler_filter || 'pending' });
        }
    };

    rcube_webmail.prototype.mail_scheduler_compose = function (data) {
        var target = this.is_framed() && parent.rcmail ? parent.rcmail : this;
        target.redirect(target.url('mail/compose', { _draft_uid: data.uid, _mbox: data.mbox }));
    };

    function scheduled_edit_init() {
        var id = rc.env.mail_scheduler_id,
            form = $('#ms-form');

        $.each(['save', 'sendnow', 'todraft', 'cancel'], function (i, cmd) {
            rc.enable_command('plugin.mail_scheduler-' + cmd, true);
        });

        form.find('#ms-recurrence').on('change', function () {
            form.find('.ms-until-row').toggleClass('hidden', $(this).val() == 'none');
        });

        rc.register_command('plugin.mail_scheduler-save', function () {
            var date = form.find('#ms-date').val(),
                time = form.find('#ms-time').val();

            if (wall(date, time) === null) {
                rc.alert_dialog(label('invaliddatetime'));
                return;
            }

            rc.http_post('plugin.mail_scheduler-action', {
                _act: 'save',
                _id: id,
                _date: date,
                _time: time,
                _recurrence: form.find('#ms-recurrence').val(),
                _until: form.find('#ms-until').val()
            }, rc.set_busy(true, 'loading'));
        }, true);

        rc.register_command('plugin.mail_scheduler-sendnow', function () {
            rc.confirm_dialog(label('confirmsendnow'), 'mail_scheduler.sendnow', function () {
                rc.http_post('plugin.mail_scheduler-action', { _act: 'sendnow', _id: id }, rc.set_busy(true, 'loading'));
            });
        }, true);

        rc.register_command('plugin.mail_scheduler-todraft', function () {
            rc.confirm_dialog(label('confirmtodraft'), 'mail_scheduler.todraft', function () {
                rc.http_post('plugin.mail_scheduler-action', { _act: 'todraft', _id: id }, rc.set_busy(true, 'loading'));
            });
        }, true);

        rc.register_command('plugin.mail_scheduler-cancel', function () {
            cancel_message(id, true);
        }, true);
    }

    /* =====================================================================
     * Ajustes · respuestas automáticas
     * ===================================================================== */

    function autoreply_list_init() {
        if (!rc.gui_objects.autoreplylist) {
            return;
        }

        rc.autoreply_list = new rcube_list_widget(rc.gui_objects.autoreplylist,
            { multiselect: false, draggable: false, keyboard: true });

        rc.autoreply_list
            .addEventListener('select', function (list) {
                var id = list.get_single_selection();

                rc.enable_command('plugin.mail_scheduler-ar-delete', !!id);

                if (id) {
                    load_frame('plugin.mail_scheduler-autoreply-edit', { _id: id });
                }
            })
            .addEventListener('keypress', function (list) {
                rc.list_keypress(list, { del: 'plugin.mail_scheduler-ar-delete' });
            })
            .init()
            .focus();

        rc.register_command('plugin.mail_scheduler-ar-add', function () {
            rc.autoreply_list.clear_selection();
            rc.enable_command('plugin.mail_scheduler-ar-delete', false);
            load_frame('plugin.mail_scheduler-autoreply-edit');
        }, true);

        rc.register_command('plugin.mail_scheduler-ar-delete', function () {
            var id = rc.autoreply_list.get_single_selection();

            if (id) {
                rc.confirm_dialog(label('confirmdeleteautoreply'), 'delete', function () {
                    rc.http_post('plugin.mail_scheduler-autoreply-action', { _act: 'delete', _id: id }, rc.set_busy(true, 'loading'));
                }, { button_class: 'delete' });
            }
        }, false);
    }

    rcube_webmail.prototype.mail_scheduler_ar_saved = function (data) {
        var list = this.autoreply_list,
            id = String(data.id);

        if (!list) {
            return;
        }

        if (list.rows[id]) {
            $(list.rows[id].obj).attr('class', 'ms-ar-' + data.state + (list.rows[id].obj.className.indexOf('selected') > -1 ? ' selected focused' : ''))
                .find('td.name').html(data.html);
        } else {
            var row = $('<tr>').attr('id', 'rcmrow' + id).addClass('ms-ar-' + data.state)
                .append($('<td class="name">').html(data.html));

            list.insert_row(row[0]);
            list.select(id);
            this.enable_command('plugin.mail_scheduler-ar-delete', true);
        }
    };

    rcube_webmail.prototype.mail_scheduler_ar_removed = function (data) {
        if (this.autoreply_list) {
            this.autoreply_list.remove_row(String(data.id));
            this.enable_command('plugin.mail_scheduler-ar-delete', false);
            this.show_contentframe(false);
        }
    };

    function autoreply_edit_init() {
        var form = $('#ms-ar-form'),
            sigs = rc.env.mail_scheduler_signatures || {},
            sig_select = form.find('#ms-ar-signature'),
            sig_preview = form.find('#ms-ar-sig-preview');

        // Editor HTML (TinyMCE) para el texto de la respuesta. En 1.5 hay que
        // pasar la configuración: editor_init() no recurre a env.editor_config.
        if (rc.editor_init && rc.env.editor_config) {
            try {
                rc.editor_init(rc.env.editor_config, 'ms-ar-body');
            } catch (e) {
                window.console && console.error(e);
            }
        }

        rc.register_command('toggle-editor', function (props, obj, e) {
            return rc.toggle_editor(props, obj, e);
        }, true);

        sig_select.on('change', function () {
            var text = sigs[$(this).val()];
            sig_preview.text(text || '').toggleClass('hidden', !text);
        }).trigger('change');

        rc.register_command('plugin.mail_scheduler-ar-save', function () {
            if (rc.editor) {
                rc.editor.save();
            }

            form.submit();
        }, true);
    }

    /* =====================================================================
     * Arranque
     * ===================================================================== */

    rc.addEventListener('init', function () {
        var task = rc.env.task,
            action = rc.env.action;

        if (task == 'mail' && action == 'compose') {
            compose_init();
        } else if (task == 'settings') {
            switch (action) {
            case 'plugin.mail_scheduler':
                scheduled_list_init();
                break;
            case 'plugin.mail_scheduler-edit':
                scheduled_edit_init();
                break;
            case 'plugin.mail_scheduler-autoreplies':
                autoreply_list_init();
                break;
            case 'plugin.mail_scheduler-autoreply-edit':
            case 'plugin.mail_scheduler-autoreply-save':
                autoreply_edit_init();
                break;
            }
        }
    });
})(jQuery);
