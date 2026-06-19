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

use FacturaScripts\Dinamic\Lib\Modelo369 as Modelo369Lib;

/**
 * Genera el fichero de texto plano de ancho fijo del modelo 369 para su presentación
 * electrónica en la Sede de la AEAT, según el diseño de registro oficial (DR369e21).
 *
 * El fichero contiene un único declarante y un único régimen: un registro de cabecera
 * (<T3690...>), la página común T36900 y las páginas de detalle del régimen seleccionado.
 *
 * @author Esteban Sánchez Martínez <esteban@factura.city>
 */
class Modelo369Txt
{
    /** Número de elementos (país/tipo IVA/base/cuota) por página de detalle. */
    const BLOCKS_PER_PAGE = 28;

    /** Longitud total de cada página vacía (espacio entre etiquetas = total - 17). */
    const EMPTY_PAGES = [
        '36900' => 110,
        '36902' => 763,
        '36903' => 2947,
        '36906' => 1687,
        '36907' => 1687,
        '36908' => 763,
        '36909' => 5803,
        '36911' => 763,
        '36912' => 2947,
    ];

    /**
     * Construye el contenido completo del fichero TXT (codificado en ISO-8859-1).
     *
     * @param string $codejercicio ejercicio fiscal (año)
     * @param string $periodo periodo del plugin ("T1".."T4" o "01".."12")
     * @param string $regime régimen ("oss", "external-oss" o "ioss")
     * @param array $data filas devueltas por Modelo369::getData()
     */
    public static function build(string $codejercicio, string $periodo, string $regime, array $data): string
    {
        $declarant = Modelo369Lib::getDeclarant($codejercicio);
        $dates = Modelo369Lib::getPeriodDates($codejercicio, $periodo);
        $sinActividad = empty($data) ? '1' : '0';

        // página común, obligatoria en todos los regímenes
        $content = self::pageEmpty('36900');

        switch ($regime) {
            case 'external-oss':
                // Régimen Exterior (VOES): sólo prestaciones de servicios
                $content .= self::dataPages('36901', $declarant, $codejercicio, $periodo, $dates, $regime, $data, $sinActividad);
                $content .= self::pageEmpty('36902');
                $content .= self::pageEmpty('36903');
                break;

            case 'ioss':
                // Régimen de Importación (IOSS): importaciones de bienes de menos de 150 €
                $content .= self::dataPages('36910', $declarant, $codejercicio, $periodo, $dates, $regime, $data, $sinActividad);
                $content .= self::pageEmpty('36911');
                $content .= self::pageEmpty('36912');
                break;

            default:
                // Régimen de la Unión (OSS): servicios en T36904 y bienes en T36905
                $services = self::filterKind($data, 'services');
                $goods = self::filterKind($data, 'goods');
                $content .= self::dataPages('36904', $declarant, $codejercicio, $periodo, $dates, $regime, $services, $sinActividad);
                if (false === empty($goods)) {
                    $content .= self::dataPages('36905', $declarant, $codejercicio, $periodo, $dates, $regime, $goods, $sinActividad);
                }
                $content .= self::pageEmpty('36906');
                $content .= self::pageEmpty('36907');
                $content .= self::pageEmpty('36908');
                $content .= self::pageEmpty('36909');
                break;
        }

        return self::headerWrap($codejercicio, $periodo, $declarant['nif'], $content);
    }

    /**
     * Campo alfanumérico: alineado a la izquierda, relleno con blancos a la derecha.
     * Convierte a ISO-8859-1 para que la longitud en bytes coincida con las posiciones.
     */
    protected static function an($value, int $len): string
    {
        $value = str_replace(["\r", "\n"], ' ', (string)$value);

        $latin = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $value);
        if ($latin === false) {
            $latin = $value;
        }

        $latin = substr($latin, 0, $len);
        return str_pad($latin, $len, ' ', STR_PAD_RIGHT);
    }

    protected static function blanks(int $len): string
    {
        return str_repeat(' ', max(0, $len));
    }

    /**
     * Genera todas las páginas de detalle de un régimen, troceando en bloques de 28 y
     * marcando como complementarias ("C") las páginas adicionales.
     */
    protected static function dataPages(string $page, array $declarant, string $codejercicio, string $periodo, array $dates, string $regime, array $rows, string $sinActividad): string
    {
        $chunks = array_chunk($rows, self::BLOCKS_PER_PAGE);
        if (empty($chunks)) {
            // página obligatoria aunque no haya datos
            $chunks = [[]];
        }

        $out = '';
        foreach ($chunks as $i => $chunk) {
            $complementary = $i > 0 ? 'C' : ' ';
            switch ($page) {
                case '36901':
                    $out .= self::pageT36901($declarant, $codejercicio, $periodo, $chunk, $complementary, $sinActividad);
                    break;

                case '36905':
                    $out .= self::pageT36905($chunk, $complementary);
                    break;

                case '36910':
                    $out .= self::pageT36910($declarant, $codejercicio, $periodo, $dates, $chunk, $complementary, $sinActividad);
                    break;

                default:
                    $out .= self::pageT36904($declarant, $codejercicio, $periodo, $dates, $chunk, $complementary, $sinActividad);
                    break;
            }
        }

        return $out;
    }

    /**
     * Convierte una fecha "DD-MM-YYYY" (formato de getPeriodDates) a "AAAAMMDD".
     */
    protected static function dateField(string $date): string
    {
        $parts = explode('-', $date);
        if (count($parts) !== 3) {
            return self::blanks(8);
        }

        return $parts[2] . $parts[1] . $parts[0];
    }

    /**
     * Construye los 28 bloques de detalle (42 posiciones cada uno). Los slots sin datos
     * se rellenan con blancos.
     */
    protected static function detailBlocks(array $chunk): string
    {
        $out = '';
        for ($i = 0; $i < self::BLOCKS_PER_PAGE; $i++) {
            if (isset($chunk[$i])) {
                $row = $chunk[$i];
                $out .= self::an(Modelo369Lib::getCountryIso($row['codpais']), 2)
                    . self::pct((float)$row['iva'])
                    . self::an('', 1)       // tipo de IVA (R/S) en blanco
                    . self::num($row['baseimponible'], 17)
                    . self::num($row['cuotaiva'], 17);
            } else {
                $out .= self::blanks(42);
            }
        }

        return $out;
    }

    /**
     * Filtra las filas por tipo de operación ("goods" o "services").
     */
    protected static function filterKind(array $data, string $kind): array
    {
        $result = [];
        foreach ($data as $row) {
            if (($row['kind'] ?? 'goods') === $kind) {
                $result[] = $row;
            }
        }

        return array_values($result);
    }

    /**
     * Envuelve el contenido con el registro de cabecera de apertura y cierre (T3690).
     */
    protected static function headerWrap(string $codejercicio, string $periodo, string $nif, string $content): string
    {
        $headerPeriod = Modelo369Lib::getHeaderPeriod($periodo);

        // apertura: "<T3690" + ejercicio(4) + periodo(2) + "0000>" + reservado(92) + NIF(9) + reservado(210)
        $opening = '<T3690' . self::an($codejercicio, 4) . self::an($headerPeriod, 2) . '0000>'
            . self::blanks(92)
            . self::an($nif, 9)
            . self::blanks(210);

        // cierre: "</T3690" + ejercicio(4) + periodo(2) + "0000>"
        $closing = '</T3690' . self::an($codejercicio, 4) . self::an($headerPeriod, 2) . '0000>';

        return $opening . $content . $closing;
    }

    /**
     * Campo numérico: 15 enteros + 2 decimales sin separador (17 pos por defecto),
     * alineado a la derecha con ceros. Si no hay dato se rellena con blancos.
     */
    protected static function num($value, int $len = 17): string
    {
        if ($value === null || $value === '') {
            return self::blanks($len);
        }

        $negative = (float)$value < 0;
        $cents = (int)round(abs((float)$value) * 100);
        $digits = str_pad((string)$cents, $negative ? $len - 1 : $len, '0', STR_PAD_LEFT);

        return $negative ? '-' . $digits : $digits;
    }

    /**
     * Página totalmente vacía (sólo etiquetas de apertura/cierre y blancos).
     */
    protected static function pageEmpty(string $page): string
    {
        $total = self::EMPTY_PAGES[$page];
        return '<T' . $page . '>' . self::blanks($total - 17) . '</T' . $page . '>';
    }

    /**
     * Página T36901 (Régimen Exterior): datos del declarante + prestaciones de servicios. 1422 pos.
     */
    protected static function pageT36901(array $declarant, string $codejercicio, string $periodo, array $chunk, string $complementary, string $sinActividad): string
    {
        return '<T36901>'
            . self::an('VOES', 5)
            . self::an('D', 1)
            . self::an('', 1)               // tipo de pago
            . self::an('', 22)              // NRC pago
            . self::num(null, 17)           // importe pagado
            . self::an($complementary, 1)
            . self::an($declarant['codiso'], 2)
            . self::an($declarant['nif'], 15)
            . self::an('', 15)              // número de operador NEUOSS (en blanco)
            . self::an($declarant['nombre'], 125)
            . self::an($codejercicio, 4)
            . self::an('T', 1)
            . self::an(Modelo369Lib::getPeriodNumber($periodo), 2)
            . self::an($sinActividad, 1)
            . self::detailBlocks($chunk)
            . self::an('', 17)              // reservado
            . '</T36901>';
    }

    /**
     * Página T36904 (Régimen Unión): datos del declarante + prestaciones de servicios. 1423 pos.
     */
    protected static function pageT36904(array $declarant, string $codejercicio, string $periodo, array $dates, array $chunk, string $complementary, string $sinActividad): string
    {
        return '<T36904>'
            . self::an('MOSS', 5)
            . self::an('D', 1)
            . self::an('', 1)               // tipo de pago (en blanco)
            . self::an('', 22)              // NRC pago
            . self::num(null, 17)           // importe pagado
            . self::an($complementary, 1)
            . self::an($declarant['codiso'], 2)
            . self::an($declarant['nif'], 15)
            . self::an($declarant['nombre'], 125)
            . self::an($codejercicio, 4)
            . self::an('T', 1)
            . self::an(Modelo369Lib::getPeriodNumber($periodo), 2)
            . self::dateField($dates['from'])
            . self::dateField($dates['to'])
            . self::an($sinActividad, 1)
            . self::detailBlocks($chunk)
            . self::an('', 17)              // reservado
            . '</T36904>';
    }

    /**
     * Página T36905 (Régimen Unión): entregas de bienes desde España. 1211 pos.
     */
    protected static function pageT36905(array $chunk, string $complementary): string
    {
        return '<T36905>'
            . self::an($complementary, 1)
            . self::detailBlocks($chunk)
            . self::an('', 17)              // reservado
            . '</T36905>';
    }

    /**
     * Página T36910 (Régimen Importación): datos del declarante + importaciones de bienes. 1454 pos.
     */
    protected static function pageT36910(array $declarant, string $codejercicio, string $periodo, array $dates, array $chunk, string $complementary, string $sinActividad): string
    {
        return '<T36910>'
            . self::an('IMPO', 5)
            . self::an('D', 1)
            . self::an('', 1)               // tipo de pago
            . self::an('', 22)              // NRC pago
            . self::num(null, 17)           // importe pagado
            . self::an($complementary, 1)
            . self::an($declarant['codiso'], 2)
            . self::an($declarant['nif'], 15)
            . self::an('', 15)              // número de operador NIOSS (en blanco)
            . self::an($declarant['nombre'], 125)
            . self::an('0', 1)              // actúa a través de intermediario
            . self::an('', 15)              // identificación del intermediario NIOSSIn
            . self::an($codejercicio, 4)
            . self::an('M', 1)
            . self::an(Modelo369Lib::getPeriodNumber($periodo), 2)
            . self::dateField($dates['from'])
            . self::dateField($dates['to'])
            . self::an($sinActividad, 1)
            . self::detailBlocks($chunk)
            . self::an('', 17)              // reservado
            . '</T36910>';
    }

    /**
     * Tipo (%) de IVA: 3 enteros + 2 decimales sin separador (5 pos). 21,00 -> "02100".
     */
    protected static function pct(float $rate): string
    {
        $cents = (int)round($rate * 100);
        return str_pad((string)$cents, 5, '0', STR_PAD_LEFT);
    }
}
