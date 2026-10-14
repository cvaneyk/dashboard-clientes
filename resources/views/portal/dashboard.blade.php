<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Dashboard · {{ $cliente->nombre }}</title>
	<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
	<style>
		:root{--green:#638E76;--green-dark:#4f7361;--green-soft:#e8efe9;--ink:#141414;--mut:#4b5563;--bg:#f6f4f2;--border:#e7e2de}
		*{box-sizing:border-box;margin:0;font-family:"Outfit",system-ui,-apple-system,"Segoe UI",sans-serif}
		body{background:var(--bg);color:var(--ink);display:flex;flex-direction:column;min-height:100vh}
		.wrap{max-width:1200px;margin:0 auto;padding-inline:clamp(16px,4vw,32px);width:100%}
		.top{background:#fff;border-bottom:1px solid var(--border);padding:14px 0}
		.top__row{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
		.top__brand{display:flex;align-items:center;gap:14px}
		.clogo{height:40px;max-width:150px;object-fit:contain;border-right:1px solid var(--border);padding-right:14px}
		.brand img{width:120px;height:auto}
		.client{font-size:.9rem;color:var(--mut)}
		.logout{border:0;background:none;color:var(--mut);font-size:.9rem;cursor:pointer;text-decoration:underline}
		.top__actions{display:flex;align-items:center;gap:16px}
		.tlink{color:var(--mut);font-size:.9rem;text-decoration:none;font-weight:600}
		.tlink:hover{color:var(--green)}
		.csel select{font:inherit;font-size:.9rem;padding:6px 10px;border:1px solid #d9e2dc;border-radius:8px;background:#fff;color:inherit;cursor:pointer;max-width:220px}
		.csel select:focus{outline:2px solid var(--green);outline-offset:1px}
		.vista{font-size:.75rem;color:var(--mut);border:1px solid #d9e2dc;border-radius:999px;padding:3px 9px;white-space:nowrap}
		.interno{background:#7a2020;color:#fff;padding:10px 0;font-size:.9rem}
		.interno a{color:#fff;margin-left:8px}
		.medidores{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin-bottom:8px}
		.med{background:#fff;border:1px solid var(--border);border-radius:14px;padding:16px;text-align:center}
		.med__name{font-weight:700;font-size:.95rem;margin-bottom:6px}
		.med svg{width:100%;max-width:190px;height:auto}
		.med__num{font-size:1.25rem;font-weight:800;margin-top:-6px}
		.med__sub{font-size:.8rem;color:var(--mut);margin-top:2px}
		.med__sub.over{color:#a13030;font-weight:700}
		.pagehead{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:clamp(22px,3vw,32px) 0 18px}
		.exports{display:flex;gap:8px}
		.xbtn{border:1.4px solid var(--border);background:#fff;border-radius:10px;padding:8px 14px;font-size:.86rem;font-weight:700;color:var(--green-dark);cursor:pointer}
		.xbtn:hover{border-color:var(--green);background:var(--green-soft)}
		h1{font-size:clamp(1.4rem,3vw,1.9rem);font-weight:800;letter-spacing:-.02em}
		.eyebrow{font-size:.82rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mut);margin:0 0 12px}

		.toolbar{display:flex;flex-wrap:wrap;gap:12px 18px;align-items:center;background:#fff;border:1px solid var(--border);border-radius:14px;padding:14px 16px;margin-bottom:26px}
		.seg{display:inline-flex;background:var(--bg);border:1px solid var(--border);border-radius:999px;padding:3px}
		.seg button{border:0;background:none;padding:7px 13px;border-radius:999px;font-size:.86rem;font-weight:600;color:var(--mut);cursor:pointer}
		.seg button.on{background:#fff;color:var(--ink);box-shadow:0 2px 8px -4px rgba(20,20,20,.4)}
		.dr{display:flex;align-items:center;gap:8px;font-size:.86rem;color:var(--mut)}
		.dr input{border:1.4px solid var(--border);border-radius:9px;padding:7px 9px;font-size:.86rem}
		.cmp{display:flex;align-items:center;gap:7px;font-size:.86rem;color:var(--mut);cursor:pointer}
		.cmp input{width:16px;height:16px;accent-color:var(--green)}
		.grow{flex:1}

		.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
		@media(max-width:820px){.kpis{grid-template-columns:repeat(2,1fr)}}
		@media(max-width:460px){.kpis{grid-template-columns:1fr}}
		.kpi{background:#fff;border:1px solid var(--border);border-radius:16px;padding:20px;box-shadow:0 14px 34px -26px rgba(20,20,20,.3)}
		.kpi__label{font-size:.84rem;color:var(--mut);font-weight:600}
		.kpi__num{font-size:clamp(1.6rem,3.2vw,2.1rem);font-weight:800;color:var(--green);letter-spacing:-.02em;margin-top:6px;line-height:1}
		.kpi__sub{font-size:.8rem;color:#9ca3af;margin-top:6px}
		.delta{font-size:.8rem;font-weight:700;margin-top:6px}
		.delta.up{color:#1c7c4a}.delta.down{color:#b0432a}.delta.flat{color:#9ca3af}

		.card{background:#fff;border:1px solid var(--border);border-radius:18px;padding:clamp(16px,2.4vw,24px);box-shadow:0 16px 40px -30px rgba(20,20,20,.3);margin-bottom:26px}
		.card__head{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:16px}
		.card__head h2{font-size:1.05rem;font-weight:800}
		.ctrls{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
		.chip{border:1px solid var(--border);background:#fff;border-radius:999px;padding:5px 12px;font-size:.82rem;font-weight:600;color:var(--mut);cursor:pointer;display:inline-flex;align-items:center;gap:7px}
		.chip.off{opacity:.45}
		.chip .dot{width:9px;height:9px;border-radius:50%}
		select.mini{border:1.4px solid var(--border);border-radius:9px;padding:6px 9px;font-size:.84rem;background:#fff;cursor:pointer}

		.canales{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:28px}
		@media(max-width:820px){.canales{grid-template-columns:1fr}}
		.canal{background:#fff;border:1px solid var(--border);border-radius:16px;padding:20px;box-shadow:0 14px 34px -26px rgba(20,20,20,.3);border-top:4px solid var(--green)}
		.canal__head{display:flex;align-items:center;gap:10px;margin-bottom:16px}
		.canal__logo{width:30px;height:30px;display:grid;place-items:center}
		.canal__logo svg{width:100%;height:100%}
		.canal__name{font-weight:800;font-size:1.02rem}
		.canal__grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 16px}
		.mini__label{font-size:.76rem;color:var(--mut)}
		.mini__num{font-size:1.12rem;font-weight:800;color:var(--ink);margin-top:2px}
		.mini__num.g{color:var(--green)}

		table{width:100%;border-collapse:collapse}
		th,td{text-align:right;padding:11px 10px;font-size:.92rem;border-top:1px solid #f0eeec}
		th{color:var(--mut);font-weight:600;font-size:.78rem;text-transform:uppercase;letter-spacing:.03em;border-top:0;cursor:pointer;user-select:none;white-space:nowrap}
		th.no{cursor:default}
		th .ar{opacity:.4;font-size:.7rem}
		th.act .ar{opacity:1;color:var(--green)}
		td:first-child,th:first-child,td:nth-child(2),th:nth-child(2){text-align:left}
		tbody tr:hover{background:#faf9f7}
		.rowc{border-left:4px solid transparent}
		.cdot{display:inline-flex;align-items:center;gap:7px;font-size:.82rem;color:var(--mut)}
		.cdot .dot{width:9px;height:9px;border-radius:50%}
		.val{color:var(--green-dark);font-weight:700}
		.search{border:1.4px solid var(--border);border-radius:10px;padding:8px 12px;font-size:.9rem;min-width:200px}
		.pager{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:14px;font-size:.86rem;color:var(--mut);flex-wrap:wrap}
		.pager .pg{display:flex;gap:6px}
		.pager button{border:1px solid var(--border);background:#fff;border-radius:8px;padding:6px 11px;cursor:pointer;font-size:.86rem}
		.pager button:disabled{opacity:.4;cursor:default}

		.foot{margin-top:auto;background:var(--ink);color:rgba(255,255,255,.7);padding:34px 0 22px}
		.foot__row{display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between}
		.foot .brand img{width:120px;height:auto}
		.foot a{color:rgba(255,255,255,.75);text-decoration:none;font-size:.9rem;margin-left:18px}
		.foot a:hover{color:var(--green)}
		.foot__copy{font-size:.82rem;color:rgba(255,255,255,.5);margin-top:14px}
		.foot__disclaimer{font-size:.75rem;color:rgba(255,255,255,.6);margin-top:16px;font-style:italic}
		.empty{color:#9ca3af;text-align:center;padding:30px;font-size:.95rem}
		@media print{
			body{background:#fff}
			.top__actions,.toolbar,.exports,.ctrls,.search,.pager,.foot a{display:none !important}
			.card,.kpi,.canal{box-shadow:none;break-inside:avoid}
			.foot{background:#fff;color:#555;padding:14px 0;border-top:1px solid var(--border)}
			.foot .brand{color:var(--ink)}
		}
	</style>
</head>
<body>
	<div class="top">
		<div class="wrap top__row">
			<div class="top__brand">
				@if ($logoUrl)
					<img class="clogo" src="{{ $logoUrl }}" alt="{{ $cliente->nombre }}">
				@endif
				<div>
					@include('portal.partials.marca')
					<div class="client">{{ $cliente->nombre }}</div>
				</div>
			</div>
			<div class="top__actions">
				@if ($visibles->count() > 1)
					{{-- Selector de cliente: recarga la página, porque el dataset
					     que se vuelca al navegador es solo el del cliente activo. --}}
					{{-- Se queda en la vista en la que estés: desde la interna,
					     cambiar de cliente no debe devolverte a la del cliente. --}}
					<form method="get" action="{{ route($esInterno ? 'portal.dashboard.interno' : 'portal.dashboard') }}" class="csel">
						<select name="cliente" onchange="this.form.submit()" aria-label="Cliente">
							@foreach ($visibles as $c)
								<option value="{{ $c->slug }}" @selected($c->id === $cliente->id)>{{ $c->nombre }}</option>
							@endforeach
						</select>
					</form>
				@endif

				<a class="tlink" href="{{ route($esInterno ? 'portal.globales.interno' : 'portal.globales') }}">Gastos globales</a>

				@if ($esAdmin)
					@if ($esInterno)
						<span class="vista">datos internos</span>
					@else
						<span class="vista">vista de cliente</span>
						<a class="tlink" href="{{ route('portal.dashboard.interno') }}">Gasto real</a>
					@endif
					<a class="tlink" href="/admin">← Panel</a>
				@else
					<a class="tlink" href="{{ route('portal.cuenta') }}">Mi cuenta</a>
					<form method="post" action="{{ route('portal.logout') }}">@csrf<button class="logout" type="submit">Salir</button></form>
				@endif
			</div>
		</div>
	</div>

	@if ($esInterno)
		{{-- Esta pantalla enseña el gasto de plataforma, que el cliente no debe
		     ver nunca. El aviso se imprime también en el PDF a propósito. --}}
		<div class="interno">
			<div class="wrap">
				<strong>Datos internos · no compartir con el cliente.</strong>
				Las cifras de coste son el <strong>gasto real pagado a la plataforma</strong>, no lo que se factura.
				<a href="{{ route('portal.dashboard') }}">Ver la vista del cliente →</a>
			</div>
		</div>
	@endif

	<div class="wrap" style="flex:1">
		<div class="pagehead">
			<h1>Rendimiento de campañas</h1>
			<div class="exports">
				<button id="btnCsv" class="xbtn" type="button">↓ CSV</button>
				<button id="btnPdf" class="xbtn" type="button">↓ PDF</button>
			</div>
		</div>

		<div class="toolbar">
			<div class="seg" id="presets">
				<button data-p="7">Últimos 7 días</button>
				<button data-p="30">Últimos 30</button>
				<button data-p="month">Este mes</button>
				<button data-p="prevmonth">Mes anterior</button>
				<button data-p="all">Todo</button>
			</div>
			<div class="dr">Desde <input type="date" id="from"> Hasta <input type="date" id="to"></div>
			<label class="cmp"><input type="checkbox" id="compare"> Comparar con periodo anterior</label>
			{{-- Agrupar por producto une la misma línea de negocio aunque esté
			     repartida en varias campañas y varios canales. --}}
			<label class="cmp">Agrupar por
				<select class="mini" id="groupBy">
					<option value="canal">Canal</option>
					<option value="producto">Producto</option>
				</select>
			</label>
			<div class="grow"></div>
			<div class="seg" id="chanfilter"></div>
		</div>

		<p class="eyebrow" id="periodLabel"></p>
		<div class="kpis" id="kpis"></div>

		<div class="card">
			<div class="card__head">
				<h2>Evolución diaria</h2>
				<div class="ctrls">
					<select class="mini" id="metric">
						<option value="c">{{ $etiquetaCoste }}</option>
						<option value="k">Clics</option>
						<option value="l">Leads</option>
						<option value="i">Impresiones</option>
						<option value="cpl">CPL</option>
						<option value="cpc">CPC</option>
						<option value="cpm">CPM</option>
						<option value="ctr">CTR</option>
					</select>
					<div class="ctrls" id="chartChips"></div>
				</div>
			</div>
			<div style="position:relative;height:340px"><canvas id="chart"></canvas></div>
		</div>

		<p class="eyebrow">Rendimiento por canal</p>
		<div class="canales" id="canales"></div>

		{{-- Consumo del objetivo del mes por línea. Se oculta entero si ninguna
		     línea tiene presupuesto asignado, para no enseñar medidores vacíos. --}}
		<div id="objetivosWrap" hidden>
			<p class="eyebrow" id="objetivosTitulo">{{ $tituloObjetivos }}</p>
			<div class="medidores" id="medidores"></div>
		</div>

		<div class="card">
			<div class="card__head">
				<h2>Desglose por campaña</h2>
				<div class="ctrls">
					<input class="search" id="search" placeholder="Buscar campaña…">
					<select class="mini" id="perpage"><option>10</option><option selected>25</option><option>50</option></select>
				</div>
			</div>
			<table>
				<thead>
					<tr id="thead">
						<th class="no">Campaña</th>
						<th class="no">Canal</th>
						<th data-k="c">{{ $etiquetaCoste }} <span class="ar">▾</span></th>
						<th data-k="i">Impresiones <span class="ar">▾</span></th>
						<th data-k="k">Clics <span class="ar">▾</span></th>
						<th data-k="l">Leads <span class="ar">▾</span></th>
						<th data-k="cpc">CPC <span class="ar">▾</span></th>
						<th data-k="cpl">CPL <span class="ar">▾</span></th>
					</tr>
				</thead>
				<tbody id="tbody"></tbody>
			</table>
			<div class="pager" id="pager"></div>
		</div>
	</div>

	<footer class="foot">
		<div class="wrap">
			<div class="foot__row">
				@include('portal.partials.marca', ['pie' => true])
				@if (config('marca.web'))
					<div>
						<a href="{{ config('marca.web') }}" target="_blank" rel="noopener">{{ preg_replace('#^https?://(www\.)?#', '', rtrim(config('marca.web'), '/')) }}</a>
					</div>
				@endif
			</div>
			<div class="foot__disclaimer">* Datos de campaña sin Fee ni IVA</div>
			<div class="foot__copy">© <span id="year"></span> {{ config('marca.nombre') }}{{ config('marca.eslogan') ? ' · '.config('marca.eslogan') : '' }}. Panel de rendimiento de campañas.</div>
		</div>
	</footer>

	<script>
	const DATA = @json($data);
	{{-- Comentario de Blade, NO de JavaScript: lo que se escribe con // viaja al
	     navegador del cliente, y aquí se habla de la vista interna. --}}
	const LBL_COSTE     = @json($etiquetaCoste);
	const TIT_OBJETIVOS = @json($tituloObjetivos);

	const CH = {
		meta:   {name:'Meta Ads',   color:'#1877F2', logo:'<svg viewBox="0 0 512 512"><path fill="#0081fb" d="M55.3 310.2c0 19.5 4.3 34.6 9.9 43.6c7.4 11.9 18.3 16.9 29.5 16.9c14.4 0 27.6-3.6 53-38.7c20.4-28.2 44.4-67.7 60.5-92.6l27.3-42c19-29.2 41-61.6 66.2-83.6C322.4 95.9 344.6 86 366.9 86c37.5 0 73.2 21.7 100.6 62.5c29.9 44.7 44.5 100.9 44.5 158.9c0 34.5-6.8 59.9-18.4 79.9c-11.2 19.4-33 38.7-69.6 38.7v-55.2c31.4 0 39.2-28.8 39.2-61.9c0-47-11-99.2-35.1-136.5c-17.1-26.5-39.4-42.6-63.8-42.6c-26.4 0-47.7 19.9-71.6 55.5c-12.7 18.9-25.8 41.9-40.4 67.9l-16.1 28.6c-32.4 57.5-40.6 70.5-56.8 92.1c-28.4 37.8-52.6 52.2-84.6 52.2c-37.9 0-61.8-16.4-76.7-41.1C5.9 364.8 0 338.4 0 308.3z"/><linearGradient id="meta-a" x1="110.989" x2="463.527" y1="265.812" y2="248.007" gradientTransform="matrix(1 0 0 -1 0 513)" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#0064e1"/><stop offset=".4" stop-color="#0064e1"/><stop offset=".83" stop-color="#0073ee"/><stop offset="1" stop-color="#0082fb"/></linearGradient><path fill="url(#meta-a)" d="M43.6 152.4C69 113.3 105.5 86 147.5 86c24.3 0 48.5 7.2 73.7 27.8c27.6 22.5 57 59.6 93.7 120.7l13.2 21.9c31.8 52.9 49.8 80.1 60.4 93c13.6 16.5 23.1 21.4 35.5 21.4c31.4 0 39.2-28.8 39.2-61.9l48.8-1.5c0 34.5-6.8 59.9-18.4 79.9c-11.2 19.4-33 38.7-69.6 38.7c-22.8 0-43-4.9-65.3-26c-17.2-16.2-37.2-44.9-52.7-70.7l-45.9-76.7c-23-38.5-44.2-67.2-56.4-80.2c-13.2-14-30.1-30.9-57.1-30.9c-21.8 0-40.4 15.3-55.9 38.8z"/><linearGradient id="meta-b" x1="73.757" x2="73.757" y1="179.548" y2="309.525" gradientTransform="matrix(1 0 0 -1 0 513)" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#0082fb"/><stop offset="1" stop-color="#0064e0"/></linearGradient><path fill="url(#meta-b)" d="M146.6 141.6c-21.8 0-40.4 15.3-55.9 38.8c-22 33.1-35.4 82.5-35.4 129.9c0 19.5 4.3 34.6 9.9 43.6L18.1 385C5.9 364.8 0 338.4 0 308.3c0-54.8 15-111.8 43.6-155.9C69 113.3 105.5 86 147.5 86z"/></svg>'},
		google: {name:'Google Ads', color:'#EA4335', logo:'<svg viewBox="0 0 24 24"><path fill="#EA4335" d="M6 12a6 6 0 0 0 11.659 2H12v-4h9.805v4H21.8c-.927 4.564-4.962 8-9.8 8c-5.523 0-10-4.477-10-10S6.477 2 12 2a9.99 9.99 0 0 1 8.282 4.393l-3.278 2.295A6 6 0 0 0 6 12"/></svg>'},
		tiktok: {name:'TikTok Ads', color:'#111827', logo:'<svg viewBox="0 0 16 16"><path fill="#111827" d="M8.3 1.01c.75-.01 1.5 0 2.25-.01c.05.89.36 1.8 1.01 2.43c.64.65 1.55.94 2.44 1.04v2.35c-.83-.03-1.66-.2-2.42-.56c-.33-.15-.63-.34-.93-.54c0 1.7 0 3.41-.01 5.1c-.04.82-.31 1.63-.78 2.3c-.75 1.12-2.06 1.85-3.4 1.87c-.82.05-1.65-.18-2.35-.6c-1.16-.69-1.98-1.97-2.1-3.33q-.03-.435 0-.87c.1-1.11.65-2.17 1.49-2.89c.95-.84 2.29-1.24 3.54-1c.01.86-.02 1.73-.02 2.59c-.57-.19-1.24-.13-1.74.22c-.37.24-.64.6-.79 1.02c-.12.3-.09.62-.08.94c.14.96 1.05 1.76 2.01 1.67c.64 0 1.26-.39 1.59-.94c.11-.19.23-.39.24-.62c.06-1.04.03-2.08.04-3.13c0-2.35 0-4.7.01-7.04"/></svg>'},
		linkedin:{name:'LinkedIn Ads',color:'#0A66C2',logo:'<svg viewBox="0 0 24 24"><path fill="#0A66C2" d="M19 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm-.5 15.5v-5.3a3.26 3.26 0 0 0-3.26-3.26c-.85 0-1.84.52-2.32 1.3v-1.11h-2.79v8.37h2.79v-4.93c0-.77.62-1.4 1.39-1.4a1.4 1.4 0 0 1 1.4 1.4v4.93zM6.88 8.56a1.68 1.68 0 0 0 1.68-1.68c0-.93-.75-1.69-1.68-1.69a1.69 1.69 0 0 0-1.69 1.69c0 .93.76 1.68 1.69 1.68m1.39 9.94v-8.37H5.5v8.37z"/></svg>'},
		spotify:{name:'Spotify Ads', color:'#1DB954', logo:'<svg viewBox="0 0 24 24"><path fill="#1DB954" d="M17.9 10.9C14.7 9 9.35 8.8 6.3 9.75c-.5.15-1-.15-1.15-.6c-.15-.5.15-1 .6-1.15c3.55-1.05 9.4-.85 13.1 1.35c.45.25.6.85.35 1.3c-.25.35-.85.5-1.3.25m-.1 2.8c-.25.35-.7.5-1.05.25c-2.7-1.65-6.8-2.15-9.95-1.15c-.4.1-.85-.1-.95-.5s.1-.85.5-.95c3.65-1.1 8.15-.55 11.25 1.35c.3.15.45.65.2 1m-1.2 2.75c-.2.3-.55.4-.85.2c-2.35-1.45-5.3-1.75-8.8-.95c-.35.1-.65-.15-.75-.45c-.1-.35.15-.65.45-.75c3.8-.85 7.1-.5 9.7 1.1c.35.15.4.55.25.85M12 2A10 10 0 0 0 2 12a10 10 0 0 0 10 10a10 10 0 0 0 10-10A10 10 0 0 0 12 2"/></svg>'},
		openai: {name:'OpenAI Ads',  color:'#10A37F', logo:'<svg viewBox="0 0 24 24"><path fill="#10A37F" d="M10.75 2c1.557 0 2.643.525 3.346 1.08c.182.143.336.288.466.424c1.858-.595 4.214.236 5.473 2.416c.778 1.348.867 2.552.738 3.438a4.5 4.5 0 0 1-.133.609c1.394 1.264 1.87 3.595.771 5.716l-.127.232c-.778 1.347-1.776 2.027-2.607 2.36a4.5 4.5 0 0 1-.598.19C17.667 20.373 15.77 22 13.25 22c-1.556 0-2.643-.524-3.345-1.08a4.5 4.5 0 0 1-.468-.425c-1.792.574-4.047-.178-5.334-2.188l-.138-.227c-.778-1.348-.867-2.552-.738-3.438a4.4 4.4 0 0 1 .133-.61c-1.445-1.311-1.903-3.767-.645-5.947c.778-1.347 1.776-2.026 2.607-2.358a4.5 4.5 0 0 1 .597-.193C6.332 3.626 8.232 2 10.75 2m4.6 15.184l-.375.215h-.001v.001l-.002.001l-.008.005l-.032.018l-.12.07l-.438.252l-1.403.81l-2.105 1.212c.463.355 1.218.732 2.384.732c2.145 0 3.415-1.53 3.415-2.81v-5.132l-1.315-.76zm-6.165.307l-.375-.216h-.001l-.002-.002l-.008-.004l-.031-.018l-.121-.07l-.438-.252l-1.402-.81l-2.102-1.212c-.075.58-.02 1.42.56 2.423c1.071 1.857 3.032 2.19 4.14 1.55l2.817-1.624l1.402-.809l.226-.13v-1.518zM13.499 9l4.291 2.477l.375.215v5.17c.54-.226 1.24-.692 1.82-1.696c1.006-1.741.461-3.488-.523-4.226l-.202-.134l-2.817-1.624l-1.403-.81l-.225-.13zM5.835 7.138c-.54.225-1.24.693-1.82 1.698l-.002-.001c-1.071 1.856-.381 3.72.725 4.36l2.818 1.625l1.402.809l.226.13l1.315-.76l-4.289-2.475l-.375-.215zm4.315 3.795v2.132L12 14.134l1.85-1.068v-2.133L12 9.865zm.6-7.433c-2.144 0-3.414 1.53-3.415 2.81v5.131l1.315.758V6.817l.375-.216l.003-.002l.008-.004l.03-.019l.122-.07l.437-.252l1.403-.809l2.104-1.215c-.463-.355-1.218-.73-2.382-.73m7.986 3.17c-1.073-1.857-3.033-2.19-4.14-1.55h-.002l-2.816 1.624l-1.403.81l-.225.129V9.2l4.665-2.692l.375.217h.003l.008.005l.03.019l.12.07l.44.252l1.402.809l2.1 1.21c.075-.579.022-1.418-.557-2.42"/></svg>'},
	};
	const chColor = p => (CH[p]?.color || '#638E76');
	const chName  = p => (CH[p]?.name || (DATA.nombres[p] || p));

	const eur = v => (Math.round(v*100)/100).toLocaleString('es-ES',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' €';
	const n0  = v => Math.round(v).toLocaleString('es-ES');
	const parse = s => new Date(s+'T00:00:00');
	const iso = d => d.toISOString().slice(0,10);
	const addDays = (s,n) => { const d=parse(s); d.setDate(d.getDate()+n); return iso(d); };

	const pct = v => (Math.round(v*100)/100).toLocaleString('es-ES') + '%';

	// Métricas del gráfico. Las derivadas (CPC, CPM, CTR…) NO se pueden sumar
	// día a día: hay que acumular los crudos y dividir al final, o la media
	// saldría mal. Por eso todas se declaran como cálculo sobre el acumulado.
	const METRICAS = {
		c:   {fmt:eur, calc:a=>a.c},
		l:   {fmt:n0,  calc:a=>a.l},
		k:   {fmt:n0,  calc:a=>a.k},
		i:   {fmt:n0,  calc:a=>a.i},
		cpl: {fmt:eur, calc:a=>a.l?a.c/a.l:0},
		cpc: {fmt:eur, calc:a=>a.k?a.c/a.k:0},
		cpm: {fmt:eur, calc:a=>a.i?a.c/a.i*1000:0},
		ctr: {fmt:pct, calc:a=>a.i?a.k/a.i*100:0},
	};

	// Paleta para las series por línea (los canales ya tienen color de marca).
	const PALETA = ['#638E76','#C2703D','#3D6FC2','#B23A6B','#8A6FBF','#3F8F8A','#C2A03D','#9A5B3D'];
	const colorLinea = (() => { const m={}; let n=0; return s => (m[s] = m[s] ?? PALETA[n++ % PALETA.length]); })();

	const present = DATA.canales.slice();
	const S = { from:DATA.min, to:DATA.max, compare:false, channel:'all', metric:'c', groupBy:'canal',
		chartCh:Object.fromEntries(present.map(p=>[p,true])), q:'', sort:'c', dir:'desc', per:25, page:1 };

	function rowsIn(from,to,channel){
		return DATA.rows.filter(r => r.f>=from && r.f<=to && (channel==='all'||r.p===channel));
	}
	function agg(rows){
		let c=0,k=0,l=0,i=0;
		for(const r of rows){c+=r.c;k+=r.k;l+=r.l;i+=r.i;}
		return {c,k,l,i, cpc:k?c/k:0, cpl:l?c/l:0, ctr:i?k/i*100:0};
	}
	function prevRange(from,to){
		const days = Math.round((parse(to)-parse(from))/86400000)+1;
		return [addDays(from,-days), addDays(from,-1)];
	}

	function renderKPIs(){
		const cur = agg(rowsIn(S.from,S.to,S.channel));
		let prev=null;
		if(S.compare){const [pf,pt]=prevRange(S.from,S.to); prev=agg(rowsIn(pf,pt,S.channel));}
		const defs=[
			[LBL_COSTE, eur(cur.c), n0(cur.i)+' impresiones', 'c', false],
			['CPC', eur(cur.cpc), n0(cur.k)+' clics', 'cpc', true],
			['CPL', eur(cur.cpl), n0(cur.l)+' leads', 'cpl', true],
			['CTR', (Math.round(cur.ctr*100)/100).toLocaleString('es-ES')+'%', 'clics / impresiones', 'ctr', false],
		];
		document.getElementById('kpis').innerHTML = defs.map(([lab,val,sub,key,lowerBetter])=>{
			let d='';
			if(prev){
				const a=cur[key], b=prev[key];
				if(b>0){
					const pchg=(a-b)/b*100;
					const better = lowerBetter ? pchg<0 : pchg>0;
					const cls = Math.abs(pchg)<0.05?'flat':(better?'up':'down');
					const arrow = pchg>0?'▲':(pchg<0?'▼':'■');
					d=`<div class="delta ${cls}">${arrow} ${Math.abs(Math.round(pchg*10)/10).toLocaleString('es-ES')}% vs periodo anterior</div>`;
				}
			}
			return `<div class="kpi"><div class="kpi__label">${lab}</div><div class="kpi__num">${val}</div><div class="kpi__sub">${sub}</div>${d}</div>`;
		}).join('');
		const label = S.from===S.to ? S.from : (S.from+' → '+S.to);
		document.getElementById('periodLabel').textContent = 'Periodo: '+label+(S.channel!=='all'?(' · '+chName(S.channel)):' · todos los canales');
	}

	function renderChannels(){
		const rows = rowsIn(S.from,S.to,S.channel);
		const groups={};
		for(const r of rows){(groups[r.p]=groups[r.p]||[]).push(r);}
		const order = Object.keys(groups).sort((a,b)=>agg(groups[b]).c-agg(groups[a]).c);
		const el=document.getElementById('canales');
		if(!order.length){el.innerHTML='<div class="empty">Sin datos en este periodo.</div>';return;}
		el.innerHTML = order.map(p=>{
			const a=agg(groups[p]);
			return `<div class="canal" style="border-top-color:${chColor(p)}">
				<div class="canal__head"><span class="canal__logo">${CH[p]?.logo||''}</span><span class="canal__name">${chName(p)}</span></div>
				<div class="canal__grid">
					<div><div class="mini__label">${LBL_COSTE}</div><div class="mini__num g">${eur(a.c)}</div></div>
					<div><div class="mini__label">Leads</div><div class="mini__num">${n0(a.l)}</div></div>
					<div><div class="mini__label">CPC</div><div class="mini__num">${eur(a.cpc)}</div></div>
					<div><div class="mini__label">CPL</div><div class="mini__num">${eur(a.cpl)}</div></div>
					<div><div class="mini__label">Clics</div><div class="mini__num">${n0(a.k)}</div></div>
					<div><div class="mini__label">Impresiones</div><div class="mini__num">${n0(a.i)}</div></div>
				</div></div>`;
		}).join('');
	}

	function renderChartChips(){
		const el=document.getElementById('chartChips');
		el.innerHTML = present.map(p=>`<span class="chip ${S.chartCh[p]?'':'off'}" data-ch="${p}"><span class="dot" style="background:${chColor(p)}"></span>${chName(p)}</span>`).join('');
		el.querySelectorAll('.chip').forEach(ch=>ch.onclick=()=>{const p=ch.dataset.ch;S.chartCh[p]=!S.chartCh[p];renderChartChips();renderChart();});
	}

	// Cuántas líneas como mucho en el gráfico: con más, deja de leerse. El resto
	// se agrupa en "Otras" para que los totales sigan cuadrando.
	const MAX_SERIES = 8;

	function acumuladoPorSerie(dates){
		const acc = {};
		for(const r of DATA.rows){
			if(r.f<S.from || r.f>S.to) continue;
			if(S.channel!=='all' ? r.p!==S.channel : !S.chartCh[r.p]) continue;
			const serie = S.groupBy==='producto' ? r.camp : r.p;
			const porFecha = acc[serie] = acc[serie] || {};
			const o = porFecha[r.f] = porFecha[r.f] || {c:0,k:0,l:0,i:0};
			o.c+=r.c; o.k+=r.k; o.l+=r.l; o.i+=r.i;
		}
		if(S.groupBy!=='producto') return acc;

		// Se ordenan por coste y las sobrantes se funden en una sola serie.
		const total = s => Object.values(acc[s]).reduce((t,o)=>t+o.c,0);
		const orden = Object.keys(acc).sort((a,b)=>total(b)-total(a));
		if(orden.length<=MAX_SERIES) return acc;
		const salen = orden.slice(MAX_SERIES);
		const otras = {};
		for(const s of salen){
			for(const [f,o] of Object.entries(acc[s])){
				const d = otras[f] = otras[f] || {c:0,k:0,l:0,i:0};
				d.c+=o.c; d.k+=o.k; d.l+=o.l; d.i+=o.i;
			}
			delete acc[s];
		}
		acc['Otras ('+salen.length+')'] = otras;
		return acc;
	}

	let chart=null;
	function renderChart(){
		const from=parse(S.from), to=parse(S.to);
		const labels=[]; const dates=[];
		for(let d=new Date(from); d<=to; d.setDate(d.getDate()+1)){const s=iso(d);dates.push(s);labels.push(s.slice(8,10)+'/'+s.slice(5,7));}

		const M = METRICAS[S.metric] || METRICAS.c;
		const acc = acumuladoPorSerie(dates);
		const porProducto = S.groupBy==='producto';
		const vacio = {c:0,k:0,l:0,i:0};

		const series = Object.keys(acc).sort((a,b)=>{
			const t = s => Object.values(acc[s]).reduce((x,o)=>x+o.c,0);
			return t(b)-t(a);
		});

		const datasets = series.map(s=>{
			const color = porProducto ? colorLinea(s) : chColor(s);
			return {
				label: porProducto ? s : chName(s),
				data: dates.map(f=>Math.round(M.calc(acc[s][f]||vacio)*100)/100),
				borderColor:color, backgroundColor:color,
				tension:.3, borderWidth:2.4, pointRadius:0, fill:false,
			};
		});

		if(chart) chart.destroy();
		chart=new Chart(document.getElementById('chart'),{type:'line',data:{labels,datasets},options:{
			responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
			plugins:{legend:{position:'bottom'},tooltip:{callbacks:{label:c=>c.dataset.label+': '+M.fmt(c.parsed.y)}}},
			scales:{y:{beginAtZero:true,grid:{color:'#f0eeec'},ticks:{callback:v=>M.fmt(v)}},x:{grid:{display:false}}}
		}});
	}

	// Medidores de consumo del objetivo del mes, por línea.
	// El objetivo es MENSUAL, así que se toma el mes en el que acaba el rango
	// elegido: comparar siete días contra un objetivo de mes no diría nada.
	function renderMedidores(){
		const wrap = document.getElementById('objetivosWrap');
		const objetivos = DATA.objetivos || {};
		const mes = S.to.slice(0,7);

		const gastado = {};
		for(const r of DATA.rows){
			if(r.f.slice(0,7)!==mes) continue;
			if(S.channel!=='all' && r.p!==S.channel) continue;
			gastado[r.camp] = (gastado[r.camp]||0) + r.c;
		}

		const items = Object.keys(objetivos)
			.map(camp=>({camp, obj:(objetivos[camp]||{})[mes]||0, val:gastado[camp]||0}))
			.filter(o=>o.obj>0)
			.sort((a,b)=>b.obj-a.obj);

		if(!items.length){ wrap.hidden = true; return; }
		wrap.hidden = false;

		const [a,m] = mes.split('-');
		const nombreMes = new Date(a, m-1, 1).toLocaleDateString('es-ES',{month:'long',year:'numeric'});
		document.getElementById('objetivosTitulo').textContent = TIT_OBJETIVOS + ' · ' + nombreMes;

		document.getElementById('medidores').innerHTML = items.map(o=>{
			const frac = o.obj? o.val/o.obj : 0;
			const pasado = frac>1;
			const color = pasado ? '#a13030' : colorLinea(o.camp);
			const largo = Math.PI*52;                       // longitud del semicírculo
			const pintado = Math.min(frac,1)*largo;
			return `<div class="med">
				<div class="med__name">${o.camp}</div>
				<svg viewBox="0 0 120 74" role="img" aria-label="${o.camp}: ${eur(o.val)} de ${eur(o.obj)}">
					<path d="M8 60 A52 52 0 0 1 112 60" fill="none" stroke="#e7e2de" stroke-width="12" stroke-linecap="round"/>
					<path d="M8 60 A52 52 0 0 1 112 60" fill="none" stroke="${color}" stroke-width="12" stroke-linecap="round" stroke-dasharray="${pintado} ${largo}"/>
				</svg>
				<div class="med__num">${eur(o.val)}</div>
				<div class="med__sub ${pasado?'over':''}">${Math.round(frac*100)}% de ${eur(o.obj)}</div>
			</div>`;
		}).join('');
	}

	function campaignRows(){
		const rows=rowsIn(S.from,S.to,S.channel);
		const map={};
		// Agrupando por producto, la misma línea de negocio se suma aunque esté
		// repartida en varias campañas y varios canales: la clave deja fuera el
		// canal y se guardan todos los que aportan, para poder mostrarlos.
		const porProducto = S.groupBy==='producto';
		for(const r of rows){
			const key = porProducto ? r.camp : (r.p+'|'+r.camp);
			const o = map[key] = map[key] || {camp:r.camp, p:r.p, canales:new Set(), c:0,k:0,l:0,i:0};
			o.canales.add(r.p);
			o.c+=r.c;o.k+=r.k;o.l+=r.l;o.i+=r.i;
		}
		let arr=Object.values(map).map(o=>({...o,cpc:o.k?o.c/o.k:0,cpl:o.l?o.c/o.l:0}));
		if(S.q){const q=S.q.toLowerCase();arr=arr.filter(o=>o.camp.toLowerCase().includes(q));}
		arr.sort((a,b)=>{const d=(b[S.sort]-a[S.sort]); return S.dir==='desc'?d:-d;});
		return arr;
	}
	function renderTable(){
		const all=campaignRows();
		const total=all.length;
		const pages=Math.max(1,Math.ceil(total/S.per));
		if(S.page>pages)S.page=pages;
		const start=(S.page-1)*S.per;
		const slice=all.slice(start,start+S.per);
		const tb=document.getElementById('tbody');
		tb.innerHTML = slice.length? slice.map(o=>{
			// Agrupado por producto, una fila puede venir de varios canales.
			const canales = [...o.canales];
			const celda = canales.map(p=>`<span class="cdot"><span class="dot" style="background:${chColor(p)}"></span>${chName(p)}</span>`).join(' ');
			return `<tr class="rowc" style="border-left-color:${chColor(canales[0])}">
			<td>${o.camp}</td>
			<td>${celda}</td>
			<td class="val">${eur(o.c)}</td><td>${n0(o.i)}</td><td>${n0(o.k)}</td><td>${n0(o.l)}</td><td>${eur(o.cpc)}</td><td>${eur(o.cpl)}</td>
		</tr>`;}).join('') : '<tr><td colspan="8" class="empty">Sin campañas para estos filtros.</td></tr>';
		document.querySelectorAll('#thead th[data-k]').forEach(th=>{
			th.classList.toggle('act', th.dataset.k===S.sort);
			th.querySelector('.ar').textContent = th.dataset.k===S.sort ? (S.dir==='desc'?'▾':'▴') : '▾';
		});
		document.getElementById('pager').innerHTML =
			`<span>${total? (start+1):0}–${Math.min(start+S.per,total)} de ${total}</span>
			 <span class="pg"><button ${S.page<=1?'disabled':''} id="prev">Anterior</button>
			 <button ${S.page>=pages?'disabled':''} id="next">Siguiente</button></span>`;
		const pv=document.getElementById('prev'),nx=document.getElementById('next');
		if(pv)pv.onclick=()=>{S.page--;renderTable();};
		if(nx)nx.onclick=()=>{S.page++;renderTable();};
	}

	function renderChanFilter(){
		const el=document.getElementById('chanfilter');
		const opts=[['all','Todos'],...present.map(p=>[p,chName(p)])];
		el.innerHTML=opts.map(([v,lab])=>`<button data-c="${v}" class="${S.channel===v?'on':''}">${lab}</button>`).join('');
		el.querySelectorAll('button').forEach(b=>b.onclick=()=>{S.channel=b.dataset.c;S.page=1;renderChanFilter();renderAll();});
	}

	function renderAll(){renderKPIs();renderChannels();renderMedidores();renderChart();renderTable();}

	// Presets
	function applyPreset(p){
		const max=DATA.max;
		if(p==='7'){S.from=maxClamp(addDays(max,-6));S.to=max;}
		else if(p==='30'){S.from=maxClamp(addDays(max,-29));S.to=max;}
		else if(p==='month'){S.from=maxClamp(max.slice(0,8)+'01');S.to=max;}
		else if(p==='prevmonth'){const d=parse(max.slice(0,8)+'01');d.setMonth(d.getMonth()-1);const s=iso(d);const end=new Date(d.getFullYear(),d.getMonth()+1,0);S.from=maxClamp(s);S.to=maxClamp(iso(end));}
		else {S.from=DATA.min;S.to=DATA.max;}
		document.getElementById('from').value=S.from;document.getElementById('to').value=S.to;S.page=1;renderAll();
	}
	const maxClamp = s => s<DATA.min?DATA.min:(s>DATA.max?DATA.max:s);

	// Init
	document.getElementById('year').textContent=new Date().getFullYear();
	document.getElementById('from').value=S.from;
	document.getElementById('from').min=DATA.min; document.getElementById('from').max=DATA.max;
	document.getElementById('to').value=S.to;
	document.getElementById('to').min=DATA.min; document.getElementById('to').max=DATA.max;
	document.getElementById('presets').querySelectorAll('button').forEach(b=>b.onclick=()=>{
		document.querySelectorAll('#presets button').forEach(x=>x.classList.remove('on'));b.classList.add('on');applyPreset(b.dataset.p);});
	document.getElementById('from').onchange=e=>{S.from=e.target.value;S.page=1;renderAll();};
	document.getElementById('to').onchange=e=>{S.to=e.target.value;S.page=1;renderAll();};
	document.getElementById('compare').onchange=e=>{S.compare=e.target.checked;renderKPIs();};
	document.getElementById('metric').onchange=e=>{S.metric=e.target.value;renderChart();};
	document.getElementById('groupBy').onchange=e=>{S.groupBy=e.target.value;S.page=1;renderAll();};
	document.getElementById('search').oninput=e=>{S.q=e.target.value;S.page=1;renderTable();};
	document.getElementById('perpage').onchange=e=>{S.per=parseInt(e.target.value,10);S.page=1;renderTable();};
	document.querySelectorAll('#thead th[data-k]').forEach(th=>th.onclick=()=>{
		const k=th.dataset.k; if(S.sort===k){S.dir=S.dir==='desc'?'asc':'desc';}else{S.sort=k;S.dir='desc';}renderTable();});

	// Exportar CSV del desglose por campaña (respeta filtros/orden actuales)
	function downloadCSV(){
		const rows=campaignRows();
		const dec=v=>(Math.round(v*100)/100).toFixed(2).replace('.',',');
		const head=['Campaña','Canal',LBL_COSTE,'Impresiones','Clics','Leads','CPC','CPL'];
		const q=s=>'"'+String(s).replace(/"/g,'""')+'"';
		const lines=[head.map(q).join(';')];
		for(const o of rows){
			lines.push([o.camp, [...o.canales].map(chName).join(' + '), dec(o.c), Math.round(o.i), Math.round(o.k), dec(o.l), dec(o.cpc), dec(o.cpl)].map(q).join(';'));
		}
		const blob=new Blob(['﻿'+lines.join('\r\n')],{type:'text/csv;charset=utf-8'});
		const a=document.createElement('a');
		a.href=URL.createObjectURL(blob);
		a.download=('informe_'+DATA.cliente+'_'+S.from+'_'+S.to+'.csv').replace(/\s+/g,'-');
		document.body.appendChild(a); a.click(); a.remove();
	}
	document.getElementById('btnCsv').onclick=downloadCSV;
	document.getElementById('btnPdf').onclick=()=>window.print();

	renderChanFilter();renderChartChips();renderAll();
	</script>
</body>
</html>
