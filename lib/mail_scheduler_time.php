<?php

/**
 * Mail Scheduler · utilidades de fecha y hora
 *
 * Todas las fechas se guardan en UTC (marca de tiempo Unix) junto con la zona
 * horaria del usuario, de modo que una repetición «cada lunes a las 08:00»
 * sigue saliendo a las 08:00 locales aunque cambie el horario de verano.
 *
 * @license GNU GPLv3+
 */
class mail_scheduler_time
{
    const RECURRENCES = ['none', 'daily', 'weekdays', 'weekly', 'monthly'];

    /**
     * Devuelve un DateTimeZone válido (UTC si el nombre no lo es).
     */
    public static function zone($tz)
    {
        try {
            return new DateTimeZone($tz ?: 'UTC');
        }
        catch (Exception $e) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * Convierte fecha (Y-m-d) y hora (H:i) locales en marca de tiempo UTC.
     *
     * @return int|false
     */
    public static function parse_local($date, $time, $tz)
    {
        $date = trim((string) $date);
        $time = trim((string) $time) ?: '00:00';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            return false;
        }

        $dt = DateTime::createFromFormat('!Y-m-d H:i', "$date $time", self::zone($tz));

        if (!$dt) {
            return false;
        }

        // Rechaza fechas «desbordadas» como 2026-02-31
        if ($dt->format('Y-m-d') !== $date) {
            return false;
        }

        return $dt->getTimestamp();
    }

    /**
     * Formatea una marca de tiempo en la zona dada.
     */
    public static function local($ts, $tz, $format = 'Y-m-d H:i')
    {
        $dt = new DateTime('@' . (int) $ts);
        $dt->setTimezone(self::zone($tz));

        return $dt->format($format);
    }

    /**
     * Calcula la siguiente ejecución de un envío periódico.
     *
     * @param int    $ts         Ejecución actual (UTC)
     * @param string $recurrence daily | weekdays | weekly | monthly
     * @param string $tz         Zona horaria del usuario
     * @param int    $anchor_day Día del mes original (para «cada mes el día 31»)
     * @param int    $now        No devolver fechas anteriores a esta
     *
     * @return int|null
     */
    public static function next_occurrence($ts, $recurrence, $tz, $anchor_day = null, $now = null)
    {
        if (!in_array($recurrence, self::RECURRENCES) || $recurrence == 'none') {
            return null;
        }

        $zone = self::zone($tz);
        $dt   = new DateTime('@' . (int) $ts);
        $dt->setTimezone($zone);

        $hour   = (int) $dt->format('H');
        $minute = (int) $dt->format('i');
        $anchor = $anchor_day ?: (int) $dt->format('j');
        $now    = $now ?: time();
        $guard  = 0;

        do {
            switch ($recurrence) {
            case 'daily':
                $dt->modify('+1 day');
                break;

            case 'weekdays':
                do {
                    $dt->modify('+1 day');
                }
                while ((int) $dt->format('N') >= 6);
                break;

            case 'weekly':
                $dt->modify('+1 week');
                break;

            case 'monthly':
                $y = (int) $dt->format('Y');
                $m = (int) $dt->format('n') + 1;
                if ($m > 12) {
                    $m = 1;
                    $y++;
                }
                $days = (int) (new DateTime(sprintf('%04d-%02d-01', $y, $m), $zone))->format('t');
                $dt->setDate($y, $m, min($anchor, $days));
                break;
            }

            // Mantiene la hora local aunque haya cambio de horario
            $dt->setTime($hour, $minute);
        }
        // Si el servidor estuvo parado, salta las ejecuciones perdidas
        while ($dt->getTimestamp() <= $now && ++$guard < 1000);

        return $dt->getTimestamp();
    }

    /**
     * Sugerencias rápidas para el diálogo de programación, calculadas en la
     * zona horaria del usuario.
     *
     * @return array Lista de [id, ts, date, time, weekday]
     */
    public static function quick_options($tz, $morning = 8, $afternoon = 15, $now = null)
    {
        $zone = self::zone($tz);
        $now  = $now ?: time();
        $base = new DateTime('@' . $now);
        $base->setTimezone($zone);

        $list = [];

        // Dentro de una hora, redondeado al cuarto de hora siguiente
        $in1h = clone $base;
        $in1h->modify('+1 hour');
        $min  = (int) $in1h->format('i');
        $in1h->setTime((int) $in1h->format('H'), 0);
        $in1h->modify('+' . (int) (ceil(($min ?: 0) / 15) * 15) . ' minutes');
        $list[] = ['id' => 'in1h', 'dt' => $in1h];

        // Esta tarde (si aún no ha pasado)
        $afternoon_dt = clone $base;
        $afternoon_dt->setTime($afternoon, 0);
        if ($afternoon_dt->getTimestamp() > $now + 1800) {
            $list[] = ['id' => 'afternoon', 'dt' => $afternoon_dt];
        }

        // Mañana por la mañana
        $tomorrow = clone $base;
        $tomorrow->modify('+1 day');
        $tomorrow->setTime($morning, 0);
        $list[] = ['id' => 'tomorrow', 'dt' => $tomorrow];

        // Mañana por la tarde (si no se ofreció «esta tarde»)
        if (count($list) < 3) {
            $tomorrow_pm = clone $tomorrow;
            $tomorrow_pm->setTime($afternoon, 0);
            $list[] = ['id' => 'tomorrowpm', 'dt' => $tomorrow_pm];
        }

        // Próximo lunes por la mañana
        $monday = clone $base;
        $monday->modify('next monday');
        $monday->setTime($morning, 0);
        $list[] = ['id' => 'monday', 'dt' => $monday];

        $out = [];
        foreach ($list as $item) {
            $out[] = [
                'id'      => $item['id'],
                'ts'      => $item['dt']->getTimestamp(),
                'date'    => $item['dt']->format('Y-m-d'),
                'time'    => $item['dt']->format('H:i'),
                'weekday' => (int) $item['dt']->format('N'),
            ];
        }

        return $out;
    }
}
