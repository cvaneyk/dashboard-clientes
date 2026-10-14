<?php

namespace App\Providers\Filament;

use App\Http\Middleware\AplicarAgencia;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->authGuard('admin')
            ->login()
            ->profile()
            // Marca de la agencia del admin (la carga AplicarAgencia en config);
            // en funciones para que se lea en cada petición y no al arrancar.
            // Sin logo, Filament pinta el nombre.
            ->brandName(fn () => config('marca.nombre'))
            ->brandLogo(fn () => config('marca.logo'))
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            // El dashboard del cliente vive fuera de Filament, así que sin este
            // enlace un admin no tiene por dónde llegar: '/' le devuelve al panel.
            ->navigationItems([
                NavigationItem::make('Ver dashboard de cliente')
                    ->url('/dashboard', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-presentation-chart-line')
                    ->sort(99),
                NavigationItem::make('Dashboard interno (gasto real)')
                    ->url('/dashboard/interno', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-eye-slash')
                    ->sort(100),
                NavigationItem::make('Gastos globales (interno)')
                    ->url('/globales/interno', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-banknotes')
                    ->sort(101),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Persistente: también en las peticiones Livewire (acciones, tablas),
            // que es donde se conectan cuentas y se lanzan sincronizaciones.
            ->authMiddleware([
                Authenticate::class,
                AplicarAgencia::class,
            ], isPersistent: true);
    }
}
