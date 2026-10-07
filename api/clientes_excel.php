<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();
$db  = getDB();
$act = $_GET['action'] ?? 'plantilla';

// Genera XML de Excel compatible con todas las versiones
function excelXML($titulo, $cabeceras, $filas) {
    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
    $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
          . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
          . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
          . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
    $xml .= '<Styles>' . "\n";
    $xml .= '<Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Bottom"/></Style>' . "\n";
    $xml .= '<Style ss:ID="title"><Font ss:Bold="1" ss:Size="12"/></Style>' . "\n";
    $xml .= '<Style ss:ID="header"><Alignment ss:Horizontal="Center"/>'
          . '<Font ss:Bold="1" ss:Color="#FFFFFF"/>'
          . '<Interior ss:Color="#1ea8a1" ss:Pattern="Solid"/></Style>' . "\n";
    $xml .= '</Styles>' . "\n";
    $xml .= '<Worksheet ss:Name="Clientes">' . "\n";
    $xml .= '<Table>' . "\n";

    // Título
    $xml .= '<Row><Cell ss:StyleID="title" ss:MergeAcross="' . (count($cabeceras)-1) . '">'
          . '<Data ss:Type="String">' . htmlspecialchars($titulo, ENT_XML1) . '</Data></Cell></Row>' . "\n";
    $xml .= '<Row></Row>' . "\n";

    // Cabeceras
    $xml .= '<Row>' . "\n";
    foreach ($cabeceras as $cab) {
        $xml .= '<Cell ss:StyleID="header"><Data ss:Type="String">'
              . htmlspecialchars($cab, ENT_XML1) . '</Data></Cell>' . "\n";
    }
    $xml .= '</Row>' . "\n";

    // Datos
    foreach ($filas as $fila) {
        $xml .= '<Row>' . "\n";
        foreach ($fila as $val) {
            $val  = (string)($val ?? '');
            $tipo = (is_numeric($val) && $val !== '') ? 'Number' : 'String';
            $xml .= '<Cell><Data ss:Type="' . $tipo . '">'
                  . htmlspecialchars($val, ENT_XML1) . '</Data></Cell>' . "\n";
        }
        $xml .= '</Row>' . "\n";
    }

    $xml .= '</Table>' . "\n";
    $xml .= '</Worksheet>' . "\n";
    $xml .= '</Workbook>' . "\n";
    return $xml;
}

if ($act === 'plantilla') {
    $cabeceras = ['Nombre','DNI','RUC','Telefono','Email','Direccion','Como nos conocio','Notas'];
    $filas = [
        ['Juan Perez Garcia','12345678','','999123456','juan@email.com','Av. Lima 123','Internet',''],
        ['Maria Lopez','87654321','','987654321','','Calle Arequipa 45','Referido',''],
    ];
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="plantilla_clientes.xls"');
    header('Cache-Control: max-age=0');
    echo excelXML('PLANTILLA DE CLIENTES - Completar y subir al sistema', $cabeceras, $filas);
    exit;
}

if ($act === 'exportar') {
    // 🔒 Candado de permiso: solo roles con "Exportar" en Clientes (o Administrador).
    if (!function_exists('canExport') || !canExport('clientes')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('🔒 No tienes permiso para exportar clientes.');
    }
    $where = "activo=1";
    try {
        $r = $db->query("SHOW COLUMNS FROM clientes LIKE 'sede_id'")->fetchAll();
        if (!empty($r) && !verTodasSedes()) $where .= " AND sede_id=" . getSede();
    } catch(Exception $e){}
    $sede_nombre = '';
    try { $sn=$db->prepare("SELECT nombre FROM sedes WHERE id=?"); $sn->execute([getSede()]); $sede_nombre=$sn->fetchColumn(); } catch(Exception $e){}
    $rows = $db->query("SELECT nombre,COALESCE(dni,'') as dni,COALESCE(ruc,'') as ruc,COALESCE(telefono,'') as telefono,COALESCE(email,'') as email,COALESCE(direccion,'') as direccion,COALESCE(como_conocio,'') as como_conocio,COALESCE(notas,'') as notas FROM clientes WHERE $where ORDER BY nombre")->fetchAll();

    $cabeceras = ['Nombre','DNI','RUC','Telefono','Email','Direccion','Como nos conocio','Notas'];
    $filas = array_map(fn($r) => array_values($r), $rows);

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="clientes_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    echo excelXML('Clientes - ' . $sede_nombre . ' - ' . date('d/m/Y'), $cabeceras, $filas);
    exit;
}
