<?php

namespace App\Services\Sync;

use App\Models\Cuenta;

/**
 * Contrato común de los conectores de API.
 *
 * Todos acaban en CampaignImporter::importRows(), así que desde fuera
 * (el comando, el botón del panel) da igual con qué plataforma se hable.
 * Lo específico de cada una —GAQL, Graph, sus tokens— no sale del conector.
 */
interface Conector
{
    /**
     * Ventana móvil de los últimos días. Se re-bajan varios a propósito:
     * las plataformas corrigen cifras hacia atrás.
     *
     * @return array{filas:int,filas_error:int,lineas_nuevas:int,gasto_real:float,periodo_inicio:?string,periodo_fin:?string,import_id:int}
     */
    public function sincronizar(Cuenta $cuenta, int $dias = 3): array;

    /**
     * Rango concreto de fechas (Y-m-d, ambas inclusive), para recuperar
     * histórico. Idempotente: rebajar días ya importados los sobrescribe.
     *
     * @return array{filas:int,filas_error:int,lineas_nuevas:int,gasto_real:float,periodo_inicio:?string,periodo_fin:?string,import_id:int}
     */
    public function sincronizarRango(Cuenta $cuenta, string $desde, string $hasta): array;
}
