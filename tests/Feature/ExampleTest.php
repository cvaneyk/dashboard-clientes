<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * La raíz del portal es privada: quien no ha entrado va al login.
     * (El test de serie de Laravel esperaba un 200 aquí, de cuando '/' era la
     * página de bienvenida.)
     */
    public function test_la_raiz_manda_al_login_si_no_has_entrado(): void
    {
        $this->get('/')->assertRedirect('/login');
    }
}
