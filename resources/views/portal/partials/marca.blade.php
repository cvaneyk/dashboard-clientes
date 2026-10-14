{{-- Logo de la marca de la instalación (config/marca.php). Sin logo, el nombre en texto. --}}
@php($logoMarca = ($pie ?? false) ? (config('marca.logo_pie') ?: config('marca.logo')) : config('marca.logo'))
<div class="brand">
	@if ($logoMarca)
		<img src="{{ $logoMarca }}" alt="{{ config('marca.nombre') }}">
	@else
		<span style="font-weight:800;font-size:1.15rem;letter-spacing:-.01em">{{ config('marca.nombre') }}</span>
	@endif
</div>
