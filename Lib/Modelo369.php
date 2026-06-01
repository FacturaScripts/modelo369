<?php
/**
 * This file is part of Modelo369 plugin for FacturaScripts
 * Copyright (C) 2026 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\Modelo369\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;

/**
 * @author Esteban Sánchez Martínez <esteban@factura.city>
 */
class Modelo369
{
    public static function getEuCountryCodes(): array
    {
        return [
            'AUT', 'BEL', 'BGR', 'CYP', 'CZE', 'DEU', 'DNK', 'EST', 'FIN', 'FRA',
            'GRC', 'HRV', 'HUN', 'IRL', 'ITA', 'LTU', 'LUX', 'LVA', 'MLT', 'NLD',
            'POL', 'PRT', 'ROU', 'SWE', 'SVN', 'SVK'
        ];
    }

    public static function getData(string $codejercicio, string $periodo): array
    {
        $db = new DataBase();
        $dates = self::getPeriodDates($codejercicio, $periodo);

        $countries = [];
        foreach (self::getEuCountryCodes() as $code) {
            $countries[] = $db->var2str($code);
        }
        $countriesIn = implode(', ', $countries);

        // El país fiscal del cliente (donde está establecido) determina la inclusión en el 369,
        // no la dirección de envío de la factura (f.codpais hereda el país de la empresa).
        // El país del cliente está en contactos (idcontactofact), no en la tabla clientes.
        $sql = 'SELECT ct.codpais,'
            . ' l.iva,'
            . ' SUM(l.pvptotal * (1 - COALESCE(f.dtopor1, 0) / 100) * (1 - COALESCE(f.dtopor2, 0) / 100)) as baseimponible,'
            . ' SUM(l.pvptotal * (1 - COALESCE(f.dtopor1, 0) / 100) * (1 - COALESCE(f.dtopor2, 0) / 100) * l.iva / 100) as cuotaiva'
            . ' FROM facturascli f'
            . ' INNER JOIN lineasfacturascli l ON l.idfactura = f.idfactura'
            . ' INNER JOIN clientes c ON c.codcliente = f.codcliente'
            . ' INNER JOIN contactos ct ON ct.idcontacto = c.idcontactofact'
            . ' WHERE f.fecha >= ' . $db->var2str($dates['from'])
            . ' AND f.fecha <= ' . $db->var2str($dates['to'])
            . ' AND ct.codpais IN (' . $countriesIn . ')'
            . ' AND l.iva > 0'
            . ' GROUP BY ct.codpais, l.iva'
            . ' ORDER BY ct.codpais ASC, l.iva ASC;';

        $data = [];
        foreach ($db->select($sql) as $row) {
            $data[] = [
                'codpais' => $row['codpais'],
                'iva' => (float)$row['iva'],
                'baseimponible' => round((float)$row['baseimponible'], 2),
                'cuotaiva' => round((float)$row['cuotaiva'], 2),
            ];
        }

        return $data;
    }

    public static function getPeriodDates(string $codejercicio, string $periodo): array
    {
        $year = (int)$codejercicio;

        switch ($periodo) {
            case 'T1':
                return ['from' => '01-01-' . $year, 'to' => '31-03-' . $year];

            case 'T2':
                return ['from' => '01-04-' . $year, 'to' => '30-06-' . $year];

            case 'T3':
                return ['from' => '01-07-' . $year, 'to' => '30-09-' . $year];

            case 'T4':
                return ['from' => '01-10-' . $year, 'to' => '31-12-' . $year];

            default:
                $month = (int)$periodo;
                if ($month < 1 || $month > 12) {
                    $month = 1;
                }
                $isoFrom = sprintf('%04d-%02d-01', $year, $month);
                $lastDay = date('d', strtotime('last day of ' . $isoFrom));
                return [
                    'from' => sprintf('%02d-%02d-%04d', 1, $month, $year),
                    'to' => sprintf('%s-%02d-%04d', $lastDay, $month, $year),
                ];
        }
    }

    public static function getPeriodOptions(string $regime): array
    {
        if ($regime === 'ioss') {
            return [
                '01' => Tools::trans('january'),
                '02' => Tools::trans('february'),
                '03' => Tools::trans('march'),
                '04' => Tools::trans('april'),
                '05' => Tools::trans('may'),
                '06' => Tools::trans('june'),
                '07' => Tools::trans('july'),
                '08' => Tools::trans('august'),
                '09' => Tools::trans('september'),
                '10' => Tools::trans('october'),
                '11' => Tools::trans('november'),
                '12' => Tools::trans('december'),
            ];
        }

        return [
            'T1' => Tools::trans('first-trimester'),
            'T2' => Tools::trans('second-trimester'),
            'T3' => Tools::trans('third-trimester'),
            'T4' => Tools::trans('fourth-trimester'),
        ];
    }

    public static function getDefaultPeriod(string $regime): string
    {
        if ($regime === 'ioss') {
            return date('m');
        }

        $month = (int)date('m');
        if ($month <= 3) {
            return 'T1';
        } elseif ($month <= 6) {
            return 'T2';
        } elseif ($month <= 9) {
            return 'T3';
        }
        return 'T4';
    }
}
