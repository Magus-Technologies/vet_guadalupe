<?php
/**
 * SunatBuilder — Construye el payload JSON que la API Laravel espera.
 *
 * Convierte los datos del dominio VetPro (venta, cliente, ítems) al formato
 * que pide GenerarComprobanteRequest. No habla con la red ni con la BD.
 */
class SunatBuilder
{
    /**
     * @param array $venta  Fila de la tabla `ventas` (con cliente embebido si se prefiere).
     * @param array $cliente Fila de la tabla `clientes`.
     * @param array $items  Filas de `venta_items`.
     * @return array Payload listo para POST /generar/comprobante.
     */
    public static function buildComprobante(array $venta, array $cliente, array $items): array
    {
        $tipo       = $venta['tipo_comprobante']; // factura | boleta
        $aplica_igv = !isset($venta['aplica_igv']) || (int)$venta['aplica_igv'] === 1;

        return [
            'endpoint'   => SUNAT_ENDPOINT,
            'documento'  => $tipo, // 'factura' o 'boleta'
            'empresa'    => self::empresa(),
            'cliente'    => self::cliente($cliente, $tipo),
            'serie'      => $venta['serie'],
            'numero'     => (string) $venta['numero'],
            'fecha_emision' => $venta['fecha'] ?? date('Y-m-d H:i:s'),
            'moneda'     => 'PEN',
            'forma_pago' => 'contado',
            'aplica_igv' => $aplica_igv,
            'detalles'   => self::detalles($items, $aplica_igv),
        ];
    }

    /**
     * Payload de nota de crédito/débito para POST /generar/nota.
     *
     * @param array $nota       Fila de `notas_credito`.
     * @param array $ventaOrig  Fila de `ventas` (el comprobante afectado).
     * @param array $cliente    Fila de `clientes`.
     * @param array $items      Filas de `venta_items` del comprobante afectado.
     */
    public static function buildNota(array $nota, array $ventaOrig, array $cliente, array $items): array
    {
        $tipoDocAfectado  = $ventaOrig['tipo_comprobante'] === 'factura' ? '01' : '03';
        $serieNumAfectado = $ventaOrig['serie'] . '-' . str_pad((string)$ventaOrig['numero'], 8, '0', STR_PAD_LEFT);
        $aplica_igv       = !isset($ventaOrig['aplica_igv']) || (int)$ventaOrig['aplica_igv'] === 1;

        return [
            'endpoint'              => SUNAT_ENDPOINT,
            'documento'             => $nota['tipo_nota'],
            'empresa'               => self::empresa(),
            'cliente'               => self::cliente($cliente, $ventaOrig['tipo_comprobante']),
            'serie'                 => $nota['serie'],
            'numero'                => (string) $nota['numero'],
            'fecha_emision'         => date('Y-m-d H:i:s'),
            'moneda'                => 'PEN',
            'serie_numero_afectado' => $serieNumAfectado,
            'cod_motivo'            => $nota['cod_motivo'],
            'des_motivo'            => $nota['des_motivo'],
            'doc_afectado'          => $ventaOrig['tipo_comprobante'],
            'tipo_doc_afectado'     => $tipoDocAfectado,
            'detalles'              => self::detalles($items, $aplica_igv),
        ];
    }

    /** Datos de la empresa emisora desde config_sunat.php */
    private static function empresa(): array
    {
        return [
            'ruc'              => SUNAT_RUC,
            'usuario'          => SUNAT_USUARIO_SOL,
            'clave'            => SUNAT_CLAVE_SOL,
            'razon_social'     => SUNAT_RAZON_SOCIAL,
            'nombreComercial'  => SUNAT_NOMBRE_COMERCIAL,
            'direccion'        => SUNAT_DIRECCION,
            'ubigueo'          => SUNAT_UBIGEO,
            'distrito'         => SUNAT_DISTRITO,
            'provincia'        => SUNAT_PROVINCIA,
            'departamento'     => SUNAT_DEPARTAMENTO,
        ];
    }

    /**
     * Resuelve el documento del cliente según tipo de comprobante y documento.
     * Factura: requiere RUC (tipo_doc=6).
     * Boleta: DNI (1), Carné Extranjería (4), Pasaporte (7), RUC de persona
     * natural (6, RUC que no empieza en 20), o "varios" (0). Un RUC 20
     * (persona jurídica) exige factura según el Reglamento de Comprobantes
     * de Pago, por lo que se rechaza en boleta.
     */
    private static function cliente(array $cli, string $tipo): array
    {
        $ruc       = trim($cli['ruc'] ?? '');
        $dni       = trim($cli['dni'] ?? '');
        $ce        = trim($cli['ce'] ?? '');
        $pasaporte = trim($cli['pasaporte'] ?? '');
        $nom       = trim($cli['nombre'] ?? '');
        $dir       = trim($cli['direccion'] ?? '-');

        // Factura → requiere RUC
        if ($tipo === 'factura') {
            if ($ruc === '' || strlen($ruc) !== 11) {
                throw new RuntimeException("El cliente '$nom' no tiene RUC válido. Las facturas requieren RUC de 11 dígitos.");
            }
            return [
                'tipo_doc'    => '6',
                'num_doc'     => $ruc,
                'rzn_social'  => $nom,
                'direccion'   => $dir,
            ];
        }

        // Boleta → puede usar DNI, CE, Pasaporte, o "varios"
        if ($dni !== '' && strlen($dni) === 8) {
            return [
                'tipo_doc'    => '1',
                'num_doc'     => $dni,
                'rzn_social'  => $nom,
                'direccion'   => $dir,
            ];
        }

        if ($ce !== '' && strlen($ce) >= 9) {
            return [
                'tipo_doc'    => '4',
                'num_doc'     => $ce,
                'rzn_social'  => $nom,
                'direccion'   => $dir,
            ];
        }

        if ($pasaporte !== '') {
            return [
                'tipo_doc'    => '7',
                'num_doc'     => $pasaporte,
                'rzn_social'  => $nom,
                'direccion'   => $dir,
            ];
        }

        // Cliente identificado solo con RUC: se permite en boleta si es
        // persona natural (RUC 10/15/16/17). RUC 20 → debe emitirse factura.
        if ($ruc !== '' && strlen($ruc) === 11) {
            if (substr($ruc, 0, 2) === '20') {
                throw new RuntimeException("El cliente '$nom' tiene RUC 20 (persona jurídica). A una empresa le corresponde factura, no boleta.");
            }
            return [
                'tipo_doc'    => '6',
                'num_doc'     => $ruc,
                'rzn_social'  => $nom,
                'direccion'   => $dir,
            ];
        }

        // Si no hay ningún documento → clientes varios
        return [
            'tipo_doc'    => '0',
            'num_doc'     => '00000000',
            'rzn_social'  => $nom !== '' ? $nom : 'CLIENTE VARIOS',
            'direccion'   => $dir,
        ];
    }

    /**
     * Transforma `venta_items` al formato esperado.
     * Cuando $aplica_igv=true, `precio_unitario` viene CON IGV incluido (Greenter
     * divide entre 1.18 internamente). Cuando $aplica_igv=false, se marca cada
     * item como inafecto (sin IGV).
     */
    private static function detalles(array $items, bool $aplica_igv = true): array
    {
        $out = [];
        foreach ($items as $i => $it) {
            $out[] = [
                'cod_producto' => (string) ($it['referencia_id'] ?? ($i + 1)),
                'unidad'       => 'NIU', // NIU=unidad, ZZ=servicio. NIU funciona para ambos en SUNAT beta.
                'descripcion'  => $it['descripcion'],
                'cantidad'     => (float) $it['cantidad'],
                'precio'       => (float) $it['precio_unitario'],
                'tipo_igv'     => $aplica_igv ? 'gravado' : 'inafecto',
            ];
        }
        return $out;
    }
}
