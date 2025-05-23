<?php

namespace App\Http\Controllers;

use App\Models\TipoProducto;
use Illuminate\Http\Request;
use App\Models\TipoProductoSociedad;
use App\Models\Sociedad;
use App\Models\SocioComercial;
use App\Models\Categoria;

class NavController extends Controller
{
    const SOCIEDAD_ADMIN_ID = 1;

    public function getNavegacionSocio($categoria, $socio_id)
    {
        $tiposProducto = TipoProducto::where('categoria_id', $categoria)->get();

        $navegacion = [];
        $navegacion[] = [
            "label" => "Mis Datos",
            "link" => '/mis-datos'
        ];
        $navegacion[] = [
            "label" => "Contratar",
            "children" => []
        ];

        $navegacion[] = [
            "label" => "Mis Productos",
            "children" => []
        ];

        $tiposProducto = TipoProducto::activos()
            ->where('categoria_id', $categoria)
            ->whereNull('padre_id')
            ->whereNull('tipo_producto_asociado')
            ->get();

        $comercial_id = SocioComercial::where('id_socio', $socio_id)->pluck('id_comercial')->first();

        if (!$comercial_id) {
            $comercial_id = Categoria::findOrFail($categoria)->comercial_responsable_id;
        }

        $navegacion[1]["children"] = $tiposProducto->map(function ($tipoProducto) use ($comercial_id) {
            return [
                "label" => 'Contratar - ' . $tipoProducto->nombre,
                "link" => "/contratacion/" . strtolower($tipoProducto->letras_identificacion) . '/' . $comercial_id
            ];
        })->toArray();
        $navegacion[2]["children"] = $tiposProducto->map(function ($tipoProducto) {
            return [
                "label" => $tipoProducto->nombre,
                "link" => "/mis-productos/" . strtolower($tipoProducto->letras_identificacion)
            ];
        })->toArray();

        return response()->json($navegacion);
    }

    // Para coger las distintas rutas de la aplicación
    public function getNavegacion($id_sociedad, $responsable)
    {
        // Coger los tipos de producto asociados con la sociedad
        $tipoProductoIds = TipoProductoSociedad::where('id_sociedad', $id_sociedad)->pluck('id_tipo_producto');

        // Coger los tipos de producto basados en los IDs obtenidos
        $tiposProducto = TipoProducto::activos()
            ->whereIn('id', $tipoProductoIds)
            ->whereNull('padre_id')
            ->whereNull('tipo_producto_asociado')
            ->get();


        $navegacion = [];
        $navegacion[] = [
            "label" => "Administración",
            "children" => []
        ];
        $navegacion[] = [
            "label" => "Gestión",
            "children" => []
        ];
        $navegacion[] = [
            "label" => "Productos",
            "children" => []
        ];


        $navegacion[0]["children"] = $tiposProducto->map(function ($tipoProducto) {
            return [
                "label" => "Informes " . $tipoProducto->nombre,
                "link" => "/informes/" . $tipoProducto->letras_identificacion
            ];
        })->toArray();
        $navegacion[1]["children"] = [
            [
                "label" => "Sociedades",
                "link" => "/sociedades"
            ],
            [
                "label" => "Tarifas",
                "link" => "/tarifas"
            ],
            [
                "label" => "Comisiones",
                "link" => "/comisiones"
            ],
            [
                "label" => "Categorías",
                "link" => "/categorias"
            ],
            [
                "label" => "Productos",
                "link" => "/gestion-productos"
            ],
            [
                "label" => "Compañías",
                "link" => "/companias"
            ],
            [
                "label" => "Socios",
                "link" => "/socios"
            ]
        ];
        $navegacion[2]["children"] = $tiposProducto->map(function ($tipoProducto) {
            return [
                "label" => $tipoProducto->nombre,
                "link" => "/operaciones/" . strtolower($tipoProducto->letras_identificacion)
            ];
        })->toArray();
        // La parte de gestion solo si el id es el mismo que la sociedad admin, despues coger tipos producto y en Administracion coger los nombres
        // y concatenarlos con Informes y en link meter /informes/:letrasIdentificacion, En Productos en el label el nombre directamente y en el link /operaciones/:letrasIdentificacion

        $sociedad = Sociedad::find($id_sociedad);
        $sociedadPadreId = $sociedad->sociedad_padre_id;

        // Condición para filtrar las opciones en el array de navegación
        if ($sociedadPadreId == env('SOCIEDAD_ADMIN_ID') && isset($navegacion[2])) {
            $navegacion[1]["children"] = array_values(array_filter($navegacion[1]["children"], function ($child) {
                return in_array($child["label"], ["Sociedades", "Comisiones", "Socios"]);
            }));
        }

        // Si no es responsable
        if ($responsable != 1) {
            // Quitar el apartado de Gestión excepto Socios.
            $navegacion[1]["children"] = array_values(array_filter($navegacion[1]["children"], function ($child) {
                return in_array($child["label"], ["Socios"]);
            }));
        } else {
            array_unshift($navegacion[0]["children"], [
                "label" => "Gestión de pagos",
                "link" => "/gestion-pagos"
            ]);
        }

        return response()->json($navegacion);
    }
}
