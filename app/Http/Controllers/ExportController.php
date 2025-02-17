<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf as PdfMpdf;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\CampoController;
use App\Http\Controllers\SociedadController;
use Illuminate\Support\Facades\Schema; // Importar el facade para el esquema

class ExportController extends Controller
{

    public function getReportData(Request $request)
    {
        // Validar los parámetros
        $request->validate([
            'tipo_producto_id' => 'required|integer',
            'fecha_desde' => 'required|date',
            'fecha_hasta' => 'required|date',
            'sociedad_id' => 'nullable|integer',
        ]);
    
        // Obtener los parámetros
        $tipoProductoId = $request->input('tipo_producto_id');
        $fechaDesde = $request->input('fecha_desde');
        $fechaHasta = $request->input('fecha_hasta');
        $sociedadId = $request->input('sociedad_id');
    
        $sociedades = SociedadController::getArrayIdSociedadesHijas($sociedadId);
    
        // Obtener las letras de identificación del tipo de producto
        $tipoProducto = DB::table('tipo_producto')->where('id', $tipoProductoId)->first();
    
        if (!$tipoProducto) {
            return response()->json(['error' => 'Tipo de producto no encontrado'], 404);
        }
    
        // Verificar si la tabla y la columna 'subproducto' existen
        $tableName = $tipoProducto->letras_identificacion;
        $hasSubproductoColumn = Schema::hasColumn($tableName, 'subproducto');
    
        // Query principal
        $data = DB::table($tableName . ' as pc')
            ->select(
                DB::raw("pc.nombre_socio + ' ' + pc.apellido_1 + ' ' + pc.apellido_2 as nombre_completo"),
                'pc.dni',
                'pc.codigo_producto',
                'pc.fecha_de_emisión',
                'pc.fecha_de_inicio',
                DB::raw(
                    $hasSubproductoColumn 
                        ? "CASE WHEN pc.subproducto IS NOT NULL THEN '". $tipoProducto->nombre ."' + ' - ' + pc.subproducto_codigo ELSE '". $tipoProducto->nombre ."' END as producto" 
                        : "'". $tipoProducto->nombre ."' as producto"
                ),
                'pc.sociedad',
                'pc.tipo_de_pago',
                DB::raw("CASE 
                            WHEN pc.comercial_creador_id IS NOT NULL THEN c.nombre
                            ELSE NULL 
                        END as referidos")
            )
            ->leftJoin('comercial as c', 'pc.comercial_creador_id', '=', 'c.id') // JOIN con la tabla comercial
            ->whereBetween('pc.fecha_de_emisión', [$fechaDesde, $fechaHasta]);
    
        // Filtrar por sociedad si se proporciona
        if (!empty($sociedadId)) {
            $data->whereIn('pc.sociedad_id', $sociedades);
        }
    
        // Ejecutar la consulta
        $results = $data->get();
    
        if (!$hasSubproductoColumn) {
            $counts = collect([
                [
                    'tipo_producto' => $tipoProducto->nombre,
                    'cantidad' => DB::table($tableName)
                        ->whereBetween('fecha_de_emisión', [$fechaDesde, $fechaHasta])
                        ->count(),
                ]
            ]);
        } else {
            // Obtener la cantidad de productos por tipo diferenciando subproductos
            $counts = DB::table($tableName)
                ->select(
                    DB::raw( "CASE 
                                    WHEN subproducto IS NOT NULL THEN CONCAT('{$tipoProducto->nombre}', ' - ', subproducto_codigo) 
                                    ELSE '{$tipoProducto->nombre}' 
                            END as tipo_producto" 
                    ),
                    DB::raw('COUNT(*) as cantidad')
                )
                ->whereBetween('fecha_de_emisión', [$fechaDesde, $fechaHasta])
                ->groupBy(
                    DB::raw("CASE 
                                    WHEN subproducto IS NOT NULL THEN CONCAT('{$tipoProducto->nombre}', ' - ', subproducto_codigo) 
                                    ELSE '{$tipoProducto->nombre}' 
                            END") 
                )
                ->get();
        }


    
        return response()->json(['data' => $results, 'counts' => $counts]);
    }
    


    public function exportToPdf($letrasIdentificacion, Request $request)
        {
            
            try {

                $id = $request->input('id');

                Log::info($id);

                if(!$id){
                    return response()->json(['error' => 'ID no proporcionado'], 400);
                }
                
                // VALORES DEL PRODUCTO
                $valores = DB::table($letrasIdentificacion)->where('id', $id)->first();

                if (!$valores) {
                    return response()->json(['error' => 'Valores no encontrados'], 404);
                }

                // Comprobar que $valores no tiene el campo 'subproducto'
                if (property_exists($valores, 'subproducto')) {
                    $tipoProducto = DB::table('tipo_producto')->where('id', $valores->subproducto)->first();
                } else {
                    // TIPO PRODUCTO
                    $tipoProducto = DB::table('tipo_producto')->where('letras_identificacion', $letrasIdentificacion)->first();
                }
                
                if (!$tipoProducto) {
                    return response()->json(['error' => 'Tipo de producto no encontrado'], 404);
                }

                $plantillasBase64 = [];

                // Lista de posibles plantillas
                $plantillaPaths = [
                    $valores->plantilla_path_1,
                    $valores->plantilla_path_2,
                    $valores->plantilla_path_3,
                    $valores->plantilla_path_4,
                    $valores->plantilla_path_5,
                    $valores->plantilla_path_6,
                    $valores->plantilla_path_7,
                    $valores->plantilla_path_8,
                ];

                Log::info($plantillaPaths);

                foreach ($plantillaPaths as $path) {
                    if ($path !== null) { // Verifica si no es nulo
                        $fullPath = storage_path('app/public/' . $path); // Ruta completa
                        
                        if (file_exists($fullPath)) { // Verifica si el archivo existe
                            $imageData = base64_encode(file_get_contents($fullPath));
                            $mimeType = mime_content_type($fullPath);
                            $plantillasBase64[] = "data:{$mimeType};base64,{$imageData}"; // Agrega la plantilla en base64
                        } else {
                            return response()->json(['error' => 'Plantilla no encontrada: ' . $fullPath], 404);
                        }
                    }
                }

                // Obtener los campos del tipo de producto con columna y fila no nulos
                $campos = CampoController::fetchCamposCertificado($tipoProducto->id);

                // LOGOS
                $camposLogos = CampoController::fetchCamposLogos($tipoProducto->id);

                foreach($camposLogos as $campoLogo){
                    if($campoLogo->tipo_logo == 'sociedad'){
                        if($valores->sociedad_id == env('SOCIEDAD_ADMIN_ID')){
                            $campoLogo->url = 'logos/logo_18.png';
                        } else {
                            $campoLogo->url = $valores->logo_sociedad_path;
                        }        
                    } else {
                        $campoLogo->url = CompaniaController::getCompanyLogo($campoLogo->entidad_id);
                    }

                    $logoPath = storage_path('app/public/' . $campoLogo->url);

                    if(file_exists($logoPath)){
                        $logoData = base64_encode(file_get_contents($logoPath));
                        $logoMimeType = mime_content_type($logoPath);
                        $campoLogo->base64 = "data:{$logoMimeType};base64,{$logoData}";
                    } else {
                        $campoLogo->base64 = '';
                    }
                }

                // Obtener y colocar los datos de tipo_producto_polizas y las pólizas relacionadas
                $polizasTipoProducto = DB::table('tipo_producto_polizas')
                ->where('tipo_producto_id', $tipoProducto->id)
                ->get();

                $polizas = DB::table('polizas')
                ->whereIn('id', $polizasTipoProducto->pluck('poliza_id'))
                ->get();


                // Generar un objeto con tipo de producto, valores, campos y base64 de la plantilla
                $data = [
                    'tipoProducto' => $tipoProducto,
                    'valores' => $valores,
                    'campos' => $campos,
                    'polizas_tipo_producto' => $polizasTipoProducto,
                    'polizas' => $polizas,
                    'base64Plantillas' => $plantillasBase64,
                    'logos' => $camposLogos
                ];

                return response()->json($data);


            } catch (\ErrorException $e) {

                return response()->json(['error' => $e->getMessage()], 500);

            }catch (\Exception $e) {
                Log::info($e);
                return response()->json(['error' => $e->getMessage()], 500);
            }
        }

    public function exportAnexoExcelToPdf($tipoAnexoId , Request $request){
        // Obtener el id del request
        $id = $request->input('id');

        // Obtener el tipoAnexo desde las letrasIdentificacion (Para coger la plantilla)
        $tipoAnexo = DB::table('tipo_producto')
        ->where('id', $tipoAnexoId)
        ->first();


        $letrasIdentificacionAnexo = $tipoAnexo->letras_identificacion;

        // NECESITAMOS TAMBIEN LOS DATOS DEL PRODUCTO PARA RELLENAR LOS CAMPOS DE LA PLANTILLA
        $tipoProducto = DB::table('tipo_producto')
        ->where('id', $tipoAnexo->tipo_producto_asociado)
        ->first();

        $valores = DB::table($tipoProducto->letras_identificacion)->where('id', $id)->first();

        $plantillasBase64 = [];

        // Lista de posibles plantillas
        $plantillaPaths = [
            $tipoAnexo->plantilla_path_1,
            $tipoAnexo->plantilla_path_2,
            $tipoAnexo->plantilla_path_3,
            $tipoAnexo->plantilla_path_4,
            $tipoAnexo->plantilla_path_5,
            $tipoAnexo->plantilla_path_6,
            $tipoAnexo->plantilla_path_7,
            $tipoAnexo->plantilla_path_8,
        ];

        foreach ($plantillaPaths as $path) {
            if ($path !== null) { // Verifica si no es nulo
                $fullPath = storage_path('app/public/' . $path); // Ruta completa
                
                if (file_exists($fullPath)) { // Verifica si el archivo existe
                    $imageData = base64_encode(file_get_contents($fullPath));
                    $mimeType = mime_content_type($fullPath);
                    $plantillasBase64[] = "data:{$mimeType};base64,{$imageData}"; // Agrega la plantilla en base64
                } else {
                    return response()->json(['error' => 'Plantilla no encontrada: ' . $fullPath], 404);
                }
            }
        }

                
        // Coger los anexos relacionados con el id del producto de la tabla con el nombre $letrasIdentificacionAnexo
        $anexos = DB::table($letrasIdentificacionAnexo)->where('producto_id', $id)->get();

        $campos = DB::table('campos')
            ->where('tipo_producto_id', $tipoAnexoId)
            ->whereNotNull('columna')
            ->whereNotNull('fila')
            ->whereNotIn('grupo', ['datos_anexo', 'datos_precio'])
            ->get();

        $camposAnexo = DB::table('campos')
            ->where('tipo_producto_id', $tipoAnexo->id)
            ->whereNotNull('columna')
            ->whereNotNull('fila')
            ->whereIn('grupo', ['datos_anexo', 'datos_precio'])
            ->get();    


        // LOGO DE LA SOCIEDAD
        if($valores->sociedad_id == env('SOCIEDAD_ADMIN_ID')){
            $logo = 'logos/Logo_CANAMA__003.png';
        } else {
            $logo = $valores->logo_sociedad_path;
        }

        $logoPath = storage_path('app/public/' . $logo);

        if(file_exists($logoPath)){
            $logoData = base64_encode(file_get_contents($logoPath));
            $logoMimeType = mime_content_type($logoPath);
            $base64Logo = "data:{$logoMimeType};base64,{$logoData}";
        } else {
            $base64Logo = '';
        }

        // Obtener y colocar los datos de tipo_producto_polizas y las pólizas relacionadas
        $polizasTipoProducto = DB::table('tipo_producto_polizas')
        ->where('tipo_producto_id', $tipoAnexoId)
        ->get();

        $polizas = DB::table('polizas')
        ->whereIn('id', $polizasTipoProducto->pluck('poliza_id'))
        ->get();


        // Agregar el logo y número de póliza de cada compañía en las celdas correspondientes
        foreach ($polizasTipoProducto as $tipoPoliza) {
            $poliza = $polizas->firstWhere('id', $tipoPoliza->poliza_id);
            $numeroPoliza = $poliza ? $poliza->numero : 'N/A';
        }

        $data = [
            'tipoProducto' => $tipoAnexo,
            'valores' => $valores,
            'campos' => $campos,
            'anexos' => $anexos,
            'camposAnexo' => $camposAnexo,
            'polizas_tipo_producto' => $polizasTipoProducto,
            'polizas' => $polizas,
            'base64Plantillas' => $plantillasBase64,
            'base64Logo' => $base64Logo
        ];

        return response()->json($data);
    }


    public function getPlantillaBase64(Request $request){
        //Coger la ruta
        $path = $request->input('path');
        $file = Storage::disk('public')->get($path);
        $base64 = base64_encode($file);
        return response()->json(['base64' => $base64]);
    }

    // public function exportExcelToPdf($letrasIdentificacion, Request $request)
    // {
    //     try{
    //         // Obtener el tipo de producto basado en las letras de identificación
    //         $tipoProducto = DB::table('tipo_producto')->where('letras_identificacion', $letrasIdentificacion)->first();
            
    //         if (!$tipoProducto) {
    //             return response()->json(['error' => 'Tipo de producto no encontrado'], 404);
    //         }

    //         // Obtener la ruta de la plantilla
    //         $plantillaPath = storage_path('app/public/' . $tipoProducto->plantilla_path);
            
    //         if (!file_exists($plantillaPath)) {
    //             return response()->json(['error' => 'Plantilla no encontrada'], 404);
    //         }

    //         // Cargar el archivo Excel
    //         $spreadsheet = IOFactory::load($plantillaPath);
    //         $sheet = $spreadsheet->getActiveSheet();

    //         // Obtener los campos del tipo de producto con columna y fila no nulos
    //         $campos = DB::table('campos')
    //             ->where('tipo_producto_id', $tipoProducto->id)
    //             ->whereNotNull('columna')
    //             ->whereNotNull('fila')
    //             ->get();

    //         // Obtener el id del request
    //         $id = $request->input('id');
            
    //         // Obtener los valores de los campos de la tabla que se llama igual que las letrasIdentificacion
    //         $valores = DB::table($letrasIdentificacion)->where('id', $id)->first();

    //         if (!$valores) {
    //             return response()->json(['error' => 'Valores no encontrados'], 404);
    //         }

    //         // Rellenar el archivo Excel con los valores obtenidos
    //         foreach ($campos as $campo) {
    //             $celda = $campo->columna . $campo->fila;
    //             // Convertir el nombre del campo a minúsculas y reemplazar espacios por guiones bajos
    //             $nombreCampo = strtolower(str_replace(' ', '_', $campo->nombre));
    //             $valor = $valores->{$nombreCampo}; 
                
    //             // Obtener el contenido existente de la celda
    //             $contenidoExistente = $sheet->getCell($celda)->getValue();
                
    //             // Concatenar el contenido existente con el nuevo valor
    //             $nuevoContenido = $contenidoExistente . ' ' . $valor;
                
    //             // Establecer el nuevo contenido en la celda
    //             $sheet->setCellValue($celda, $nuevoContenido);
                
    //         }

    //         foreach ($campos as $campo) {
    //             $celda = $campo->columna . $campo->fila;
    //             $sheet->getStyle($celda)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_NONE);
    //         }

    //         // Establecer el área de impresión (ajusta las celdas según sea necesario)
    //         $sheet->getPageSetup()->setPrintArea('D1:L54');

    //         // Guardar el archivo Excel con los nuevos datos
    //         $tempExcelPath = storage_path('app/public/temp/plantilla_' . time() . '.xlsx');
    //         $writer = new Xlsx($spreadsheet);
    //         $writer->save($tempExcelPath);

    //         // Convertir el archivo Excel a HTML para generar el PDF
    //         $htmlWriter = IOFactory::createWriter($spreadsheet, 'Html');
    //         ob_start();
    //         $htmlWriter->save('php://output');
    //         $htmlContent = ob_get_clean();

    //         // Eliminar imágenes en base64 del contenido HTML
    //         $htmlContent = $this->removeBase64Images($htmlContent);            

    //         // Añadir estilos CSS al contenido HTML
    //         $htmlContent = $this->adjustHtmlStyles($htmlContent);

    //         // ELIMINAR BORDES QUE SE GENERAN EN EL PDF:
    //         $htmlContent = str_replace('border: 1px solid black;', '', $htmlContent);

    //         // Guardar el contenido HTML en un archivo para revisión
    //         $htmlFilePath = storage_path('app/public/temp/plantilla_' . time() . '.html');
    //         file_put_contents($htmlFilePath, $htmlContent);

            
    //         // Crear el PDF desde el contenido HTML
    //         $pdf = Pdf::loadHTML($htmlContent);

    //         // Guardar el archivo PDF temporalmente
    //         $tempPdfPath = storage_path('app/public/temp/plantilla_' . time() . '.pdf');
    //         $pdf->save($tempPdfPath);
            
    //         // Devolver el archivo PDF como respuesta HTTP con el tipo de contenido adecuado
    //         $fileContent = file_get_contents($tempPdfPath);
    //         $response = response($fileContent, 200)->header('Content-Type', 'application/pdf');

    //         // Eliminar los archivos temporales
    //         unlink($tempExcelPath);
    //         unlink($tempPdfPath);

    //         return $response;

    //     }catch(\Exception $e){

    //         return response()->json(['error' => $e->getMessage()], 500);

    //     }
        
    // }

    // private function adjustHtmlStyles($htmlContent)
    // {
    //     // Reducir espacios en blanco y ajustar el tamaño de la letra
    //     $styles = "
    //         <style>
    //             body {
    //                 font-size: 6px;
    //                 line-height: 1;
    //             }
    //             h1, h2, h3, h4, h5, h6 {
    //                 margin: 2px 0;
    //             }
    //             p {
    //                 margin: 2px 0;
    //             }
    //             table {
    //                 width: 100%;
    //                 border-collapse: collapse;
    //             }
    //             td, th {
    //                 padding: 2px;
    //             }
    //         </style>
    //     ";

    //     // Insertar los estilos en el contenido HTML
    //     $htmlContent = str_replace('</head>', $styles . '</head>', $htmlContent);

    //     return $htmlContent;
    // }

    // private function removeBase64Images($htmlContent)
    // {
    //     // Eliminar todas las imágenes en base64
    //     $htmlContent = preg_replace('/<img[^>]+src="data:image\/[^;]+;base64,([^"]+)"[^>]*>/', '', $htmlContent);
    //     return $htmlContent;
    // }

}
