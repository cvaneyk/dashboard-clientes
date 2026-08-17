@php
	$meses_es = [1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
	$eur = fn($v) => number_format((float)$v, 2, ',', '.') . ' €';
	$num = fn($v) => number_format((float)$v, 0, ',', '.');
	$labels = [];
	$dCoste = [];
	$dClics = [];
	foreach ($serie as $fecha => $row) {
		$labels[] = (int) substr($fecha, 8, 2);
		$dCoste[] = round($row['coste'], 2);
		$dClics[] = (int) $row['clics'];
	}
@endphp
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
		body{background:var(--bg);color:var(--ink);padding:0 0 60px}
		.top{background:#fff;border-bottom:1px solid var(--border);padding:16px 0}
		.wrap{max-width:1160px;margin:0 auto;padding-inline:clamp(16px,4vw,32px)}
		.top__row{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
		.brand{font-size:1.25rem;font-weight:800;letter-spacing:-.02em}
		.brand b{color:var(--green)}
		.client{font-size:.92rem;color:var(--mut)}
		.top__right{display:flex;align-items:center;gap:12px}
		select{border:1.6px solid var(--border);border-radius:10px;padding:9px 12px;font-size:.95rem;background:#fff;cursor:pointer}
		.logout{border:0;background:none;color:var(--mut);font-size:.9rem;cursor:pointer;text-decoration:underline}
		h1{font-size:clamp(1.4rem,3vw,1.9rem);font-weight:800;letter-spacing:-.02em;margin:clamp(24px,4vw,36px) 0 6px}
		.period{color:var(--mut);font-size:1rem;margin-bottom:26px;text-transform:capitalize}
		.eyebrow{font-size:.85rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mut);margin:0 0 14px}
		.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:30px}
		@media(max-width:820px){.kpis{grid-template-columns:repeat(2,1fr)}}
		@media(max-width:460px){.kpis{grid-template-columns:1fr}}
		.kpi{background:#fff;border:1px solid var(--border);border-radius:16px;padding:22px 22px;box-shadow:0 14px 34px -26px rgba(20,20,20,.3)}
		.kpi__label{font-size:.85rem;color:var(--mut);font-weight:600}
		.kpi__num{font-size:clamp(1.7rem,3.4vw,2.2rem);font-weight:800;color:var(--green);letter-spacing:-.02em;margin-top:6px;line-height:1}
		.kpi__sub{font-size:.82rem;color:#9ca3af;margin-top:6px}
		.card{background:#fff;border:1px solid var(--border);border-radius:18px;padding:clamp(18px,2.6vw,28px);box-shadow:0 16px 40px -30px rgba(20,20,20,.3);margin-bottom:26px}
		.card h2{font-size:1.1rem;font-weight:800;margin-bottom:16px}
		/* Canales */
		.canales{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:30px}
		@media(max-width:820px){.canales{grid-template-columns:1fr}}
		.canal{background:#fff;border:1px solid var(--border);border-radius:16px;padding:22px;box-shadow:0 14px 34px -26px rgba(20,20,20,.3)}
		.canal__head{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
		.canal__name{font-weight:800;font-size:1.05rem}
		.badge{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em;padding:4px 10px;border-radius:999px;background:var(--green-soft);color:var(--green-dark)}
		.canal__grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 16px}
		.mini__label{font-size:.78rem;color:var(--mut)}
		.mini__num{font-size:1.15rem;font-weight:800;color:var(--ink);margin-top:2px}
		.mini__num.g{color:var(--green)}
		table{width:100%;border-collapse:collapse}
		th,td{text-align:right;padding:12px 10px;font-size:.94rem;border-top:1px solid #f0eeec}
		th{color:var(--mut);font-weight:600;font-size:.82rem;text-transform:uppercase;letter-spacing:.03em;border-top:0}
		td:first-child,th:first-child,td:nth-child(2),th:nth-child(2){text-align:left}
		tbody tr:hover{background:var(--green-soft)}
		.tot td{font-weight:800;border-top:2px solid var(--border)}
		.val{color:var(--green-dark);font-weight:700}
		.chip{font-size:.75rem;font-weight:700;color:var(--mut);background:var(--bg);border:1px solid var(--border);border-radius:999px;padding:3px 9px}
	</style>
</head>
<body>
	<div class="top">
		<div class="wrap top__row">
			<div>
				<div class="brand">oli<b>media</b></div>
				<div class="client">{{ $cliente->nombre }}</div>
			</div>
			<div class="top__right">
				<form method="get" action="{{ route('portal.dashboard') }}">
					<select name="mes" onchange="this.form.submit()">
						@foreach ($meses as $ym)
							@php [$y,$m] = explode('-', $ym); @endphp
							<option value="{{ $ym }}" @selected($mesActual->format('Y-m') === $ym)>
								{{ ucfirst($meses_es[(int)$m]) }} {{ $y }}
							</option>
						@endforeach
					</select>
				</form>
				<form method="post" action="{{ route('portal.logout') }}">
					@csrf
					<button class="logout" type="submit">Salir</button>
				</form>
			</div>
		</div>
	</div>

	<div class="wrap">
		<h1>Resumen de campañas</h1>
		<p class="period">{{ $meses_es[(int)$mesActual->month] }} {{ $mesActual->year }}</p>

		{{-- Consolidado --}}
		<p class="eyebrow">Consolidado del mes · todos los canales</p>
		<div class="kpis">
			<div class="kpi">
				<div class="kpi__label">Inversión</div>
				<div class="kpi__num">{{ $eur($kpis['coste']) }}</div>
				<div class="kpi__sub">{{ $num($kpis['impresiones']) }} impresiones</div>
			</div>
			<div class="kpi">
				<div class="kpi__label">CPC</div>
				<div class="kpi__num">{{ $eur($kpis['cpc']) }}</div>
				<div class="kpi__sub">{{ $num($kpis['clics']) }} clics</div>
			</div>
			<div class="kpi">
				<div class="kpi__label">CPL</div>
				<div class="kpi__num">{{ $eur($kpis['cpl']) }}</div>
				<div class="kpi__sub">{{ $num($kpis['leads']) }} leads</div>
			</div>
			<div class="kpi">
				<div class="kpi__label">CTR</div>
				<div class="kpi__num">{{ number_format($kpis['ctr'], 2, ',', '.') }}%</div>
				<div class="kpi__sub">clics / impresiones</div>
			</div>
		</div>

		<div class="card">
			<h2>Evolución diaria</h2>
			<div style="position:relative;height:340px"><canvas id="chart"></canvas></div>
		</div>

		{{-- Por canal --}}
		@if ($porCanal->count())
			<p class="eyebrow">Rendimiento por canal</p>
			<div class="canales">
				@foreach ($porCanal as $c)
					<div class="canal">
						<div class="canal__head">
							<span class="canal__name">{{ $c['nombre'] }}</span>
							<span class="badge">{{ $c['plataforma'] }}</span>
						</div>
						<div class="canal__grid">
							<div><div class="mini__label">Inversión</div><div class="mini__num g">{{ $eur($c['coste']) }}</div></div>
							<div><div class="mini__label">Leads</div><div class="mini__num">{{ $num($c['leads']) }}</div></div>
							<div><div class="mini__label">CPC</div><div class="mini__num">{{ $eur($c['cpc']) }}</div></div>
							<div><div class="mini__label">CPL</div><div class="mini__num">{{ $eur($c['cpl']) }}</div></div>
							<div><div class="mini__label">Clics</div><div class="mini__num">{{ $num($c['clics']) }}</div></div>
							<div><div class="mini__label">Impresiones</div><div class="mini__num">{{ $num($c['imp']) }}</div></div>
						</div>
					</div>
				@endforeach
			</div>
		@endif

		<div class="card">
			<h2>Desglose por campaña</h2>
			<table>
				<thead>
					<tr><th>Campaña</th><th>Canal</th><th>Inversión</th><th>Clics</th><th>CPC</th><th>Leads</th><th>CPL</th></tr>
				</thead>
				<tbody>
					@foreach ($porLinea as $l)
						<tr>
							<td>{{ $l['nombre'] }}</td>
							<td><span class="chip">{{ $l['plataforma'] }}</span></td>
							<td class="val">{{ $eur($l['coste']) }}</td>
							<td>{{ $num($l['clics']) }}</td>
							<td>{{ $eur($l['cpc']) }}</td>
							<td>{{ $num($l['leads']) }}</td>
							<td>{{ $eur($l['cpl']) }}</td>
						</tr>
					@endforeach
					<tr class="tot">
						<td>Total</td>
						<td></td>
						<td class="val">{{ $eur($kpis['coste']) }}</td>
						<td>{{ $num($kpis['clics']) }}</td>
						<td>{{ $eur($kpis['cpc']) }}</td>
						<td>{{ $num($kpis['leads']) }}</td>
						<td>{{ $eur($kpis['cpl']) }}</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>

	<script>
		const ctx = document.getElementById('chart');
		new Chart(ctx, {
			data: {
				labels: @json($labels),
				datasets: [
					{ type: 'bar', label: 'Inversión (€)', data: @json($dCoste), backgroundColor: '#638E76', borderRadius: 6, yAxisID: 'y', order: 2 },
					{ type: 'line', label: 'Clics', data: @json($dClics), borderColor: '#c9754a', backgroundColor: '#c9754a', tension: 0.35, borderWidth: 2, pointRadius: 0, yAxisID: 'y1', order: 1 }
				]
			},
			options: {
				responsive: true, maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				plugins: { legend: { position: 'bottom' } },
				scales: {
					y:  { position: 'left',  title: { display: true, text: '€' }, grid: { color: '#f0eeec' } },
					y1: { position: 'right', title: { display: true, text: 'Clics' }, grid: { drawOnChartArea: false } },
					x:  { grid: { display: false } }
				}
			}
		});
	</script>
</body>
</html>
