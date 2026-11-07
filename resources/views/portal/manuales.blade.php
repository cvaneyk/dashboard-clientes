<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>{{ $plataforma ? 'Conectar ' . $manuales[$plataforma]['nombre'] : 'Manuales de conexión' }} · {{ config('marca.nombre') }}</title>
	<style>
		:root{--green:#638E76;--green-dark:#4f7361;--green-soft:#e8efe9;--ink:#141414;--mut:#4b5563;--bg:#f6f4f2;--border:#e7e2de}
		*{box-sizing:border-box;margin:0;font-family:"Outfit",system-ui,-apple-system,"Segoe UI",sans-serif}
		html{scroll-behavior:smooth}
		body{background:var(--bg);color:var(--ink);line-height:1.6}
		.top{background:#fff;border-bottom:1px solid var(--border)}
		.top__row,.page{max-width:1120px;margin:0 auto;padding-left:16px;padding-right:16px}
		.top__row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding-top:14px;padding-bottom:14px}
		.brand img{max-height:40px;width:auto}
		.tlink{color:var(--mut);font-size:.9rem;text-decoration:none;font-weight:600;white-space:nowrap}
		.tlink:hover{color:var(--green)}
		.page{padding-top:32px;padding-bottom:64px}
		.layout{display:grid;grid-template-columns:230px minmax(0,1fr);gap:32px;align-items:start}
		.side{position:sticky;top:20px;max-height:calc(100vh - 40px);overflow-y:auto;font-size:.92rem}
		.side h2{font-size:.75rem;letter-spacing:.06em;text-transform:uppercase;color:var(--mut);margin:0 0 6px;font-weight:700}
		.side ul{list-style:none;padding:0;margin:0 0 24px}
		.side a{display:block;color:var(--mut);text-decoration:none;padding:5px 10px;border-radius:8px}
		.side a:hover{color:var(--green-dark)}
		.side a[aria-current]{background:var(--green-soft);color:var(--green-dark);font-weight:700}
		.doc{background:#fff;border:1px solid var(--border);border-radius:16px;padding:36px;min-width:0}
		h1{font-size:1.75rem;line-height:1.2;font-weight:800;margin-bottom:8px;overflow-wrap:anywhere}
		.intro{color:var(--mut);margin-bottom:24px}
		.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(280px,100%),1fr));gap:14px}
		.card{display:block;background:#fff;border:1px solid var(--border);border-radius:14px;padding:18px;text-decoration:none;color:inherit}
		.card:hover{border-color:var(--green)}
		.card b{display:block;margin-bottom:4px}
		.card span{color:var(--mut);font-size:.92rem}
		.md h1{margin-bottom:20px}
		.md h2{font-size:1.25rem;font-weight:800;margin:36px 0 12px;padding-top:20px;border-top:1px solid var(--border);scroll-margin-top:12px}
		.md h3{font-size:1rem;font-weight:700;margin:22px 0 8px}
		.md p,.md ul,.md ol,.md blockquote{margin-bottom:12px}
		.md ul,.md ol{padding-left:24px}
		.md li{margin-bottom:6px}
		.md a{color:var(--green-dark);overflow-wrap:anywhere}
		.md code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.88em;background:var(--bg);border:1px solid var(--border);border-radius:5px;padding:1px 5px;overflow-wrap:anywhere}
		.md blockquote{background:var(--green-soft);border-left:4px solid var(--green);border-radius:0 10px 10px 0;padding:12px 16px}
		.md blockquote p:last-child{margin-bottom:0}
		.tabla{overflow-x:auto;margin-bottom:16px}
		.md table{border-collapse:collapse;width:100%;font-size:.92rem}
		.md th,.md td{border:1px solid var(--border);padding:8px 10px;text-align:left;vertical-align:top}
		.md th{background:var(--bg);font-weight:700}
		@media (max-width:820px){
			.layout{grid-template-columns:minmax(0,1fr)}
			.side{position:static;max-height:none}
			.side .apartados{display:none}
			.side ul{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:0}
			.doc{padding:22px}
		}
		@media print{
			.top,.side{display:none}
			.layout{display:block}
			body{background:#fff}
			.doc{border:0;padding:0}
		}
	</style>
</head>
<body>
	<div class="top">
		<div class="top__row">
			@include('portal.partials.marca')
			<a class="tlink" href="/admin">← Panel</a>
		</div>
	</div>

	<div class="page">
		@if ($plataforma)
			<div class="layout">
				<aside class="side">
					<nav aria-label="Plataformas">
						<h2>Plataformas</h2>
						<ul>
							@foreach ($manuales as $clave => $m)
								<li><a href="{{ route('portal.manuales.show', $clave) }}" @if ($clave === $plataforma) aria-current="page" @endif>{{ $m['nombre'] }}</a></li>
							@endforeach
						</ul>
					</nav>
					<nav class="apartados" aria-label="En este manual">
						<h2>En este manual</h2>
						<ul>
							@foreach ($manual['secciones'] as $id => $titulo)
								<li><a href="#{{ $id }}">{{ $titulo }}</a></li>
							@endforeach
						</ul>
					</nav>
				</aside>
				{{-- HTML de resources/manuales/*.md, ficheros del repo: nunca entrada de usuario. --}}
				<main class="doc md">{!! str_replace(['<table>', '</table>'], ['<div class="tabla"><table>', '</table></div>'], $manual['html']) !!}</main>
			</div>
		@else
			<h1>Manuales de conexión</h1>
			<p class="intro">Elige la plataforma que quieres conectar al portal.</p>
			<div class="grid">
				@foreach ($manuales as $clave => $m)
					<a class="card" href="{{ route('portal.manuales.show', $clave) }}">
						<b>{{ $m['nombre'] }}</b>
						<span>{{ $m['resumen'] }}</span>
					</a>
				@endforeach
			</div>
		@endif
	</div>
</body>
</html>
