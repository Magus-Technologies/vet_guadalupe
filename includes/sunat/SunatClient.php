<?php
/**
 * SunatClient — Cliente HTTP para la API api-sunat-laravel.
 *
 * Solo se encarga de hablar con la API: hace POST con JSON y devuelve
 * un array con la respuesta decodificada. No conoce nada del dominio
 * (ventas, clientes, etc).
 */
class SunatClient
{
    private string $baseUrl;
    private int    $timeout;

    public function __construct(?string $baseUrl = null, ?int $timeout = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? SUNAT_API_URL, '/');
        $this->timeout = $timeout ?? (defined('SUNAT_API_TIMEOUT') ? SUNAT_API_TIMEOUT : 60);
    }

    /** Genera el XML firmado de un comprobante (factura/boleta). */
    public function generarComprobante(array $payload): array
    {
        return $this->post('/generar/comprobante', $payload);
    }

    /** Genera el XML firmado de una nota de crédito/débito. */
    public function generarNota(array $payload): array
    {
        return $this->post('/generar/nota', $payload);
    }

    /** Envía el XML firmado a SUNAT y devuelve el CDR. */
    public function enviarDocumento(array $payload): array
    {
        return $this->post('/enviar/documento/electronico', $payload);
    }

    /** Llamada POST genérica con cuerpo JSON. */
    private function post(string $path, array $payload): array
    {
        $url  = $this->baseUrl . $path;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'estado'   => false,
                'mensaje'  => "Error de red al llamar a $url: $curlErr",
                'http'     => 0,
            ];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return [
                'estado'  => false,
                'mensaje' => "Respuesta no-JSON (HTTP $httpCode): " . substr($response, 0, 300),
                'http'    => $httpCode,
                'raw'     => $response,
            ];
        }

        $data['http'] = $httpCode;
        return $data;
    }
}
