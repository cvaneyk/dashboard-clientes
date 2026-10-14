<?php

namespace App\Models\Concerns;

use App\Support\AgenciaActual;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Aísla un modelo por agencia con un global scope: un usuario de una agencia
 * no puede leer, editar ni borrar filas de otra, venga la consulta del panel,
 * de un controlador, de un desplegable o de un enlace con un id ajeno (sale
 * 404). A quién se filtra lo decide AgenciaActual.
 *
 * Solo `clientes` (y `plantillas_email`) llevan agencia_id: el resto de tablas
 * cuelgan de un cliente y se filtran por subconsulta, así el importador y los
 * conectores no tienen que rellenar nada nuevo al escribir.
 *
 * Cada modelo que lo usa implementa filtrarPorAgencia().
 */
trait DeAgencia
{
    public static function bootDeAgencia(): void
    {
        static::addGlobalScope('agencia', function (Builder $query) {
            if (AgenciaActual::filtra()) {
                static::filtrarPorAgencia($query, AgenciaActual::id());
            }
        });
    }

    abstract protected static function filtrarPorAgencia(Builder $query, ?int $agenciaId): void;

    /** Filtro directo por una columna agencia_id (null = filas sin agencia). */
    protected static function porColumnaAgencia(Builder $query, ?int $agenciaId, string $columna = 'agencia_id'): void
    {
        $columna = $query->qualifyColumn($columna);

        $agenciaId === null
            ? $query->whereNull($columna)
            : $query->where($columna, $agenciaId);
    }

    /** Subconsulta con los ids de los clientes de una agencia, para whereIn. */
    protected static function idsClientes(?int $agenciaId): Closure
    {
        return fn (QueryBuilder $sub) => $agenciaId === null
            ? $sub->select('id')->from('clientes')->whereNull('agencia_id')
            : $sub->select('id')->from('clientes')->where('agencia_id', $agenciaId);
    }

    /** Subconsulta con los ids de las líneas de los clientes de una agencia. */
    protected static function idsLineas(?int $agenciaId): Closure
    {
        return fn (QueryBuilder $sub) => $sub->select('id')->from('lineas')
            ->whereIn('cliente_id', static::idsClientes($agenciaId));
    }

    /** Subconsulta con los ids de las cuentas de los clientes de una agencia. */
    protected static function idsCuentas(?int $agenciaId): Closure
    {
        return fn (QueryBuilder $sub) => $sub->select('id')->from('cuentas')
            ->whereIn('cliente_id', static::idsClientes($agenciaId));
    }
}
