<?php

namespace App\Filament\Resources\Agencias\Pages;

use App\Filament\Resources\Agencias\AgenciaResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateAgencia extends CreateRecord
{
    protected static string $resource = AgenciaResource::class;

    /** Crea el primer admin de la agencia con los datos de su sección del formulario. */
    protected function afterCreate(): void
    {
        User::create([
            'name'       => $this->data['admin_nombre'],
            'email'      => $this->data['admin_email'],
            'password'   => $this->data['admin_password'], // cast "hashed"
            'agencia_id' => $this->record->id,
            'es_admin'   => true,
        ]);
    }
}
