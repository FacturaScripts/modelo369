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
use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Core\DataSrc\Paises;
use FacturaScripts\Core\Lib\ProductType;
use FacturaScripts\Core\Lib\Vies;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Ejercicio;

/**
 * @author Esteban Sánchez Martínez <esteban@factura.city>
 */
class Modelo369
{
    /**
     * Convierte un código de país alfa-3 (codpais) al código de país del fichero de la AEAT:
     * ISO alfa-2 (codiso), con "EL" para Grecia según el diseño de registro DR369e21.
     */
    public static function getCountryIso(?string $codpais): string
    {
        if (empty($codpais)) {
            return '';
        }

        $pais = Paises::get($codpais);
        $codiso = $pais->codiso ?? '';
        return empty($codiso) ? '' : Vies::vatPrefix($codiso);
    }

    public static function getData(string $codejercicio, string $periodo): array
    {
        $db = new DataBase();
        $dates = self::getPeriodDates($codejercicio, $periodo);

        // países de la UE según el core, excluyendo España (las ventas nacionales no van en el 369)
        $countries = [];
        foreach (Paises::all() as $pais) {
            if ($pais->codpais !== 'ESP' && Paises::miembroUE($pais->codpais)) {
                $countries[] = $db->var2str($pais->codpais);
            }
        }
        if (empty($countries)) {
            return [];
        }
        $countriesIn = implode(', ', $countries);

        // El país fiscal del cliente (donde está establecido) determina la inclusión en el 369,
        // no la dirección de envío de la factura (f.codpais hereda el país de la empresa).
        // El país del cliente está en contactos (idcontactofact), no en la tabla clientes.
        // Clasificamos cada línea como servicio o bien según el tipo del producto asociado
        // (Producto.tipo = 'Servicio' -> servicios; resto, incluido sin producto -> bienes),
        // porque el modelo 369 separa prestaciones de servicios y entregas de bienes.
        $kindCase = "CASE WHEN p.tipo = " . $db->var2str(ProductType::SERVICE) . " THEN 'services' ELSE 'goods' END";

        $sql = 'SELECT ct.codpais,'
            . ' l.iva,'
            . ' ' . $kindCase . ' as kind,'
            . ' SUM(l.pvptotal * (1 - COALESCE(f.dtopor1, 0) / 100) * (1 - COALESCE(f.dtopor2, 0) / 100)) as baseimponible,'
            . ' SUM(l.pvptotal * (1 - COALESCE(f.dtopor1, 0) / 100) * (1 - COALESCE(f.dtopor2, 0) / 100) * l.iva / 100) as cuotaiva'
            . ' FROM facturascli f'
            . ' INNER JOIN lineasfacturascli l ON l.idfactura = f.idfactura'
            . ' INNER JOIN clientes c ON c.codcliente = f.codcliente'
            . ' INNER JOIN contactos ct ON ct.idcontacto = c.idcontactofact'
            . ' LEFT JOIN productos p ON p.idproducto = l.idproducto'
            . ' WHERE f.fecha >= ' . $db->var2str($dates['from'])
            . ' AND f.fecha <= ' . $db->var2str($dates['to'])
            . ' AND ct.codpais IN (' . $countriesIn . ')'
            . ' AND l.iva > 0'
            . ' GROUP BY ct.codpais, l.iva, ' . $kindCase
            . ' ORDER BY ct.codpais ASC, l.iva ASC;';

        $data = [];
        foreach ($db->select($sql) as $row) {
            $data[] = [
                'codpais' => $row['codpais'],
                'iva' => (float)$row['iva'],
                'kind' => $row['kind'],
                'baseimponible' => round((float)$row['baseimponible'], 2),
                'cuotaiva' => round((float)$row['cuotaiva'], 2),
            ];
        }

        return $data;
    }

    /**
     * Devuelve los datos del declarante (titular) a partir del ejercicio seleccionado:
     * NIF, nombre/razón social y código ISO alfa-2 del país de la empresa.
     */
    public static function getDeclarant(string $codejercicio): array
    {
        $declarant = ['nif' => '', 'nombre' => '', 'codiso' => 'ES'];

        $ejercicio = new Ejercicio();
        if (false === $ejercicio->loadFromCode($codejercicio)) {
            return $declarant;
        }

        $empresa = Empresas::get($ejercicio->idempresa);
        if (empty($empresa->idempresa)) {
            return $declarant;
        }

        $declarant['nif'] = $empresa->cifnif ?? '';
        $declarant['nombre'] = $empresa->nombre ?? '';
        $declarant['codiso'] = self::getCountryIso($empresa->codpais);

        return $declarant;
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

    /**
     * Periodo tal y como debe aparecer en la cabecera del fichero:
     * trimestral "1T".."4T" (el plugin lo guarda como "T1".."T4") y mensual "01".."12".
     */
    public static function getHeaderPeriod(string $periodo): string
    {
        if (strlen($periodo) === 2 && $periodo[0] === 'T') {
            return $periodo[1] . 'T';
        }

        return $periodo;
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

    /**
     * Número de periodo (1..4 trimestral, 1..12 mensual) con dos posiciones.
     */
    public static function getPeriodNumber(string $periodo): string
    {
        if (strlen($periodo) === 2 && $periodo[0] === 'T') {
            return '0' . $periodo[1];
        }

        return str_pad((string)(int)$periodo, 2, '0', STR_PAD_LEFT);
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

    /**
     * Tipo de periodo: "M" para el régimen de importación (IOSS, mensual), "T" en el resto.
     */
    public static function getPeriodType(string $regime): string
    {
        return $regime === 'ioss' ? 'M' : 'T';
    }

    /**
     * Código de régimen tal y como lo espera el fichero: MOSS (Unión), VOES (Exterior),
     * IMPO (Importación).
     */
    public static function getRegimeCode(string $regime): string
    {
        switch ($regime) {
            case 'external-oss':
                return 'VOES';

            case 'ioss':
                return 'IMPO';

            default:
                return 'MOSS';
        }
    }
}
