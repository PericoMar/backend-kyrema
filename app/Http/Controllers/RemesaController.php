<?php

namespace App\Http\Controllers;

use App\Models\GiroBancario;
use App\Models\Pago;
use Illuminate\Http\Request;
use App\Models\RemesaDescarga;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use App\Services\Remesas\Q19Generator;


class RemesaController extends Controller
{

    public function storeGiroBancario(Request $request)
    {
        $validated = $request->validate([
            'referencia'            => 'required|string',
            'nombre_cliente'        => 'required|string',
            'dni'                   => 'nullable|string',
            'importe'               => 'required|numeric',
            'fecha_firma_mandato'   => 'required|date',
            'iban_cliente'          => 'required|string',
            'auxiliar'              => 'nullable|string',
            'residente'             => 'nullable|string|in:S,N',
            'referencia_mandato'    => 'required|string',
            'fecha_cobro'           => 'required|date',
            'referencia_adeudo'     => 'required|string',
            'tipo_adeudo'           => 'required|in:FRST,RCUR,OOFF,FNAL',
            'concepto'              => 'required|string',

            'letras_identificacion' => 'required|string',
            'producto_id'           => 'required|integer',
            'fecha'                 => 'required|date',
        ]);

        // Crear registro en la tabla general de pagos
        $pago = Pago::create([
            'referencia'            => $validated['referencia'],
            'letras_identificacion' => $validated['letras_identificacion'],
            'producto_id'           => $validated['producto_id'],
            'tipo_pago'             => 'giro',
            'monto'                 => $validated['importe'],
            'fecha'                 => $validated['fecha'],
            'estado'                => 'pending',
        ]);

        // Crear giro bancario asociado
        $giro = GiroBancario::create([
            'pago_id'               => $pago->id,
            'referencia'            => $validated['referencia'],
            'nombre_cliente'        => $validated['nombre_cliente'],
            'dni'                   => $validated['dni'] ?? null,
            'importe'               => $validated['importe'],
            'fecha_firma_mandato'   => $validated['fecha_firma_mandato'],
            'iban_cliente'          => $validated['iban_cliente'],
            'auxiliar'              => $validated['auxiliar'] ?? null,
            'residente'             => $validated['residente'] ?? 'S',
            'referencia_mandato'    => $validated['referencia_mandato'],
            'fecha_cobro'           => $validated['fecha_cobro'],
            'referencia_adeudo'     => $validated['referencia_adeudo'],
            'tipo_adeudo'           => $validated['tipo_adeudo'],
            'concepto'              => $validated['concepto'],
        ]);

        return response()->json([
            'message' => 'Pago por giro bancario registrado correctamente',
            'giro'    => $giro,
            'pago'    => $pago,
        ]);
    }

    public function descargarPorFechas(Request $request)
    {
        $desde = $request->query('desde');
        $hasta = $request->query('hasta');
        $id_comercial = $request->query('id_comercial');

        $giros = GiroBancario::whereBetween('created_at', [$desde, $hasta])
            ->whereNull('remesa_descarga_id')
            ->get();

        if ($giros->isEmpty()) {
            return response()->json(['message' => 'No hay giros en ese rango'], 404);
        }

        // DATOS DEL ACREEDOR (empresa)
        $empresa = [
            'nombre' => 'Nombre SL',
            'iban' => 'ES9121000418450200051332',
            'bic' => 'CAIXESBBXXX',
            'identificador_sepa' => 'ES21ZZZB12345678',
        ];

        $referencia = 'REM_' . now()->format('YmdHis');
        $fechaCobro = $giros->first()->fecha_cobro;

        $xml = app(Q19Generator::class)
            ->generar($giros, $empresa, $referencia, $fechaCobro);

        $filename = "remesas/{$referencia}.xml";
        Storage::put($filename, $xml);

        // Guardar la descarga
        $descarga = RemesaDescarga::create([
            'ruta_xml' => $filename,
            'fecha_inicio' => $desde,
            'fecha_fin' => $hasta,
            'descargado_en' => Carbon::now()->format('Y-m-d\TH:i:s'),
            'id_comercial' => $id_comercial,
        ]);

        // Asociar cada giro a esta descarga
        foreach ($giros as $giro) {
            $giro->update(['remesa_descarga_id' => $descarga->id]);
        }

        return response()->download(storage_path("app/{$filename}"))->deleteFileAfterSend();
    }
}
