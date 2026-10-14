<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Linea;
use App\Models\MetricaDiaria;
use App\Models\PresupuestoEvento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Datos que alimentan los gráficos nuevos (evolutivo por línea, métricas
 * derivadas y medidores de objetivo).
 *
 * El dibujo lo hace el navegador y no se puede comprobar desde aquí; lo que sí
 * se fija es el contrato: qué cifras salen del servidor y con qué forma, que es
 * donde un error pasa desapercibido.
 *
 * Los nombres van sin acentos a propósito: el dataset viaja como JSON y
 * json_encode escapa lo no-ASCII, así que un assertSee con tilde no casaría.
 */
class DashboardGraficosTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $cliente;
    private Cuenta $cuenta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cliente = Cliente::create([
            'nombre' => 'Mi universidad',
            'slug'   => 'mi-universidad',
            'activo' => true,
        ]);

        $this->cuenta = Cuenta::create([
            'cliente_id'    => $this->cliente->id,
            'plataforma'    => 'meta',
            'nombre_cuenta' => 'Mi universidad · Meta',
            'activo'        => true,
        ]);
    }

    private function linea(string $nombre, ?string $producto = null, ?float $objetivo = null): Linea
    {
        $linea = Linea::create([
            'cliente_id' => $this->cliente->id,
            'cuenta_id'  => $this->cuenta->id,
            'plataforma' => 'meta',
            'nombre'     => $nombre,
            'producto'   => $producto,
            'match_key'  => Linea::normalizar($nombre),
            'nivel'      => 'campania',
            'activo'     => true,
        ]);

        if ($objetivo !== null) {
            PresupuestoEvento::create([
                'linea_id'       => $linea->id,
                'desde'          => now()->startOfMonth()->toDateString(),
                'porcentaje'     => $objetivo,
                'estado'         => 'activa',
            ]);
        }

        MetricaDiaria::create([
            'cliente_id'  => $this->cliente->id,
            'cuenta_id'   => $this->cuenta->id,
            'linea_id'    => $linea->id,
            'plataforma'  => 'meta',
            'match_key'   => $linea->match_key,
            'fecha'       => now()->toDateString(),
            'impresiones' => 10000,
            'clics'       => 200,
            'leads'       => 8,
            'gasto_real'  => 50,
        ]);

        return $linea;
    }

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin',
            'email'    => 'admin@example.com',
            'password' => 'secretito',
            'es_admin' => true,
        ]);
    }

                        public function test_el_grafico_ofrece_agrupar_por_linea_y_las_metricas_derivadas(): void
    {
        $this->linea('FISIO_LEADADS', 'Fisio', 600);

        $respuesta = $this->actingAs($this->admin(), 'admin')
            ->get('/dashboard')
            ->assertSuccessful();

        $respuesta->assertSee('id="groupBy"', false);
        $respuesta->assertSee('value="producto"', false);
        $respuesta->assertSee('value="cpm"', false);
        $respuesta->assertSee('value="ctr"', false);
    }

    /**
     * Tres campañas del mismo producto en tres canales distintos tienen que
     * llegar al navegador con la MISMA etiqueta: es lo que permite sumarlas en
     * una sola fila al agrupar por producto.
     */
    public function test_el_mismo_producto_en_varios_canales_comparte_etiqueta(): void
    {
        foreach (['meta', 'google', 'tiktok'] as $i => $plataforma) {
            $cuenta = Cuenta::create([
                'cliente_id'    => $this->cliente->id,
                'plataforma'    => $plataforma,
                'nombre_cuenta' => 'Mi universidad · ' . $plataforma,
                'activo'        => true,
            ]);

            $linea = Linea::create([
                'cliente_id' => $this->cliente->id,
                'cuenta_id'  => $cuenta->id,
                'plataforma' => $plataforma,
                // Nombres de campaña distintos, mismo producto.
                'nombre'     => 'PODO_' . strtoupper($plataforma),
                'producto'   => 'Podologia',
                'match_key'  => Linea::normalizar('PODO_' . $plataforma),
                'nivel'      => 'campania',
                'activo'     => true,
            ]);

            MetricaDiaria::create([
                'cliente_id'  => $this->cliente->id,
                'cuenta_id'   => $cuenta->id,
                'linea_id'    => $linea->id,
                'plataforma'  => $plataforma,
                'match_key'   => $linea->match_key,
                'fecha'       => now()->toDateString(),
                'impresiones' => 1000,
                'clics'       => 100,
                'leads'       => 2,
                'gasto_real'  => 10 * ($i + 1),
            ]);
        }

        $contenido = $this->actingAs($this->admin(), 'admin')
            ->get('/dashboard')
            ->assertSuccessful()
            ->getContent();

        // Las tres filas llevan la misma etiqueta de producto y distinto canal.
        $this->assertSame(3, substr_count($contenido, '"camp":"Podologia"'));
        foreach (['"p":"meta"', '"p":"google"', '"p":"tiktok"'] as $canal) {
            $this->assertStringContainsString($canal, $contenido);
        }
        // Y el nombre de la campaña no se usa como etiqueta cuando hay producto.
        $this->assertStringNotContainsString('PODO_META', $contenido);
    }

    public function test_el_cliente_tambien_ve_los_graficos_nuevos(): void
    {
        $this->linea('FISIO_LEADADS', 'Fisio', 600);

        $usuario = User::create([
            'name'       => 'Cliente',
            'email'      => 'cliente@ejemplo.es',
            'password'   => 'secretito',
            'cliente_id' => $this->cliente->id,
        ]);

        $this->actingAs($usuario)
            ->get('/dashboard')
            ->assertSuccessful()
            ->assertSee('id="medidores"', false)
            ->assertSee('id="groupBy"', false);
    }
}
