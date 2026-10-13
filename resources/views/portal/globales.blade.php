<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Gastos globales · OliMedia</title>
	<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
	<style>
		:root{--green:#638E76;--green-dark:#4f7361;--green-soft:#e8efe9;--ink:#141414;--mut:#4b5563;--bg:#f6f4f2;--border:#e7e2de}
		*{box-sizing:border-box;margin:0;font-family:"Outfit",system-ui,-apple-system,"Segoe UI",sans-serif}
		body{background:var(--bg);color:var(--ink);display:flex;flex-direction:column;min-height:100vh}
		.wrap{max-width:1200px;margin:0 auto;padding-inline:clamp(16px,4vw,32px);width:100%}
		.top{background:#fff;border-bottom:1px solid var(--border);padding:14px 0}
		.top__row{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
		.brand img{width:120px;height:auto}
		.client{font-size:.9rem;color:var(--mut)}
		.logout{border:0;background:none;color:var(--mut);font-size:.9rem;cursor:pointer;text-decoration:underline}
		.top__actions{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
		.tlink{color:var(--mut);font-size:.9rem;text-decoration:none;font-weight:600}
		.tlink:hover{color:var(--green)}
		.vista{font-size:.75rem;color:var(--mut);border:1px solid #d9e2dc;border-radius:999px;padding:3px 9px;white-space:nowrap}
		.interno{background:#7a2020;color:#fff;padding:10px 0;font-size:.9rem}
		.interno a{color:#fff;margin-left:8px}
		.pagehead{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:clamp(22px,3vw,32px) 0 18px}
		.exports{display:flex;gap:8px}
		.xbtn{border:1.4px solid var(--border);background:#fff;border-radius:10px;padding:8px 14px;font-size:.86rem;font-weight:700;color:var(--green-dark);cursor:pointer}
		.xbtn:hover{border-color:var(--green);background:var(--green-soft)}
		h1{font-size:clamp(1.4rem,3vw,1.9rem);font-weight:800;letter-spacing:-.02em}
		.eyebrow{font-size:.82rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mut);margin:0 0 12px}

		.toolbar{display:flex;flex-wrap:wrap;gap:12px 18px;align-items:center;background:#fff;border:1px solid var(--border);border-radius:14px;padding:14px 16px;margin-bottom:26px}
		.seg{display:inline-flex;flex-wrap:wrap;background:var(--bg);border:1px solid var(--border);border-radius:999px;padding:3px}
		.seg button{border:0;background:none;padding:7px 13px;border-radius:999px;font-size:.86rem;font-weight:600;color:var(--mut);cursor:pointer}
		.seg button.on{background:#fff;color:var(--ink);box-shadow:0 2px 8px -4px rgba(20,20,20,.4)}
		.dr{display:flex;align-items:center;gap:8px;font-size:.86rem;color:var(--mut);flex-wrap:wrap}
		.dr input{border:1.4px solid var(--border);border-radius:9px;padding:7px 9px;font-size:.86rem}
		.cmp{display:flex;align-items:center;gap:7px;font-size:.86rem;color:var(--mut);cursor:pointer}
		.cmp input{width:16px;height:16px;accent-color:var(--green)}
		select.mini{border:1.4px solid var(--border);border-radius:9px;padding:6px 9px;font-size:.84rem;background:#fff;cursor:pointer;max-width:220px}

		.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:28px}
		.kpi{background:#fff;border:1px solid var(--border);border-radius:16px;padding:20px;box-shadow:0 14px 34px -26px rgba(20,20,20,.3)}
		.kpi__label{font-size:.84rem;color:var(--mut);font-weight:600}
		.kpi__num{font-size:clamp(1.5rem,3vw,2rem);font-weight:800;color:var(--green);letter-spacing:-.02em;margin-top:6px;line-height:1}
		.kpi__num.neg{color:#a13030}
		.kpi__sub{font-size:.8rem;color:#9ca3af;margin-top:6px}
		.delta{font-size:.8rem;font-weight:700;margin-top:6px}
		.delta.up{color:#1c7c4a}.delta.down{color:#b0432a}.delta.flat{color:#9ca3af}

		.card{background:#fff;border:1px solid var(--border);border-radius:18px;padding:clamp(16px,2.4vw,24px);box-shadow:0 16px 40px -30px rgba(20,20,20,.3);margin-bottom:26px}
		.card__head{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:16px}
		.card__head h2{font-size:1.05rem;font-weight:800}
		.hint{font-size:.8rem;color:#9ca3af}

		.tablewrap{overflow-x:auto}
		table{width:100%;border-collapse:collapse}
		th,td{text-align:right;padding:11px 10px;font-size:.92rem;border-top:1px solid #f0eeec;white-space:nowrap}
		th{color:var(--mut);font-weight:600;font-size:.78rem;text-transform:uppercase;letter-spacing:.03em;border-top:0;cursor:pointer;user-select:none}
		th.no{cursor:default}
		th .ar{opacity:.4;font-size:.7rem}
		th.act .ar{opacity:1;color:var(--green)}
		td:first-child,th:first-child{text-align:left}
		tbody tr{border-left:4px solid transparent}
		tbody tr.drill{cursor:pointer}
		tbody tr:hover{background:#faf9f7}
		tfoot td{font-weight:800;border-top:2px solid var(--border)}
		.cdot{display:inline-flex;align-items:center;gap:8px;font-weight:600}
		.cdot .dot{width:10px;height:10px;border-radius:50%;flex:none}
		.val{color:var(--green-dark);font-weight:700}
		.neg{color:#a13030}
		.share{display:inline-block;width:60px;height:6px;background:#f0eeec;border-radius:3px;vertical-align:middle;margin-left:8px;overflow:hidden}
		.share span{display:block;height:100%;background:var(--green)}

		.foot{margin-top:auto;background:var(--ink);color:rgba(255,255,255,.7);padding:34px 0 22px}
		.foot .brand img{width:120px;height:auto}
		.foot__copy{font-size:.82rem;color:rgba(255,255,255,.5);margin-top:14px}
		.foot__disclaimer{font-size:.75rem;color:rgba(255,255,255,.6);margin-top:16px;font-style:italic}
		.empty{color:#9ca3af;text-align:center;padding:30px;font-size:.95rem}
		@media print{
			body{background:#fff}
			.top__actions,.toolbar,.exports,.hint{display:none !important}
			.card,.kpi{box-shadow:none;break-inside:avoid}
			.foot{background:#fff;color:#555;padding:14px 0;border-top:1px solid var(--border)}
		}
	</style>
</head>
<body>
	<div class="top">
		<div class="wrap top__row">
			<div>
				<div class="brand"><img src="https://olimedia.es/wp-content/themes/olimedia/assets/img/olimedia-logo.png" alt="OliMedia Agencia de Marketing"></div>
				<div class="client">
					@if ($visibles->count() > 1)
						{{ $visibles->count() }} clientes
					@else
						{{ $visibles->first()->nombre }}
					@endif
				</div>
			</div>
			<div class="top__actions">
				<a class="tlink" href="{{ route($esInterno ? 'portal.dashboard.interno' : 'portal.dashboard') }}">← Dashboard</a>
				@if ($esAdmin)
					@if ($esInterno)
						<span class="vista">datos internos</span>
					@else
						<span class="vista">vista de cliente</span>
						<a class="tlink" href="{{ route('portal.globales.interno') }}">Gasto real</a>
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
		{{-- Aviso también en el PDF a propósito: esta pantalla lleva el gasto de
		     plataforma y el margen, que el cliente no debe ver nunca. --}}
		<div class="interno">
			<div class="wrap">
				<strong>Datos internos · no compartir con el cliente.</strong>
				Gasto real pagado a la plataforma, lo facturado al cliente y el margen entre ambos.
				Un margen negativo suele ser gasto sin tramo de facturación.
				<a href="{{ route('portal.globales') }}">Ver la vista del cliente →</a>
			</div>
		</div>
	@endif

	<div class="wrap" style="flex:1">
		<div class="pagehead">
			<h1>Gastos globales</h1>
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
				<button data-p="all" class="on">Todo</button>
			</div>
			<div class="dr">Desde <input type="date" id="from"> Hasta <input type="date" id="to"></div>
			<label class="cmp"><input type="checkbox" id="compare"> Comparar con periodo anterior</label>
		</div>

		<div class="toolbar">
			{{-- Con un solo cliente visible, agrupar o filtrar por cliente no
			     aporta nada; el interruptor aparece en cuanto haya más de uno. --}}
			@if ($visibles->count() > 1)
				<span class="cmp">Unificar por</span>
				<div class="seg" id="groupBy">
					<button data-g="p" class="on">Canal</button>
					<button data-g="cl">Cliente</button>
				</div>
				<label class="cmp">Cliente
					<select class="mini" id="fCliente">
						<option value="all">Todos</option>
						@foreach ($visibles as $c)
							<option value="{{ $c->slug }}">{{ $c->nombre }}</option>
						@endforeach
					</select>
				</label>
			@endif
			<label class="cmp">Canal
				<select class="mini" id="fCanal"><option value="all">Todos</option></select>
			</label>
		</div>

		<p class="eyebrow" id="periodLabel"></p>
		<div class="kpis" id="kpis"></div>

		<div class="card">
			<div class="card__head">
				<h2 id="tablaTitulo">Por canal</h2>
				<span class="hint" id="drillHint"></span>
			</div>
			<div class="tablewrap">
				<table>
					<thead><tr id="thead"></tr></thead>
					<tbody id="tbody"></tbody>
					<tfoot id="tfoot"></tfoot>
				</table>
			</div>
		</div>

		<div class="card">
			<div class="card__head">
				<h2>Evolución</h2>
				<div class="cmp">
					<select class="mini" id="metric"></select>
					<select class="mini" id="paso">
						<option value="d">Por día</option>
						<option value="s">Por semana</option>
						<option value="m">Por mes</option>
					</select>
				</div>
			</div>
			<div style="position:relative;height:340px"><canvas id="chart"></canvas></div>
		</div>
	</div>

	<footer class="foot">
		<div class="wrap">
			<div class="brand"><img src="https://olimedia.es/wp-content/themes/olimedia/assets/img/olimedia-logo.png" alt="OliMedia Agencia de Marketing"></div>
			<div class="foot__disclaimer">* Datos de campaña sin Fee ni IVA</div>
			<div class="foot__copy">© <span id="year"></span> OliMedia · Agencia de marketing y publicidad. Gastos globales.</div>
		</div>
	</footer>

	<script>
	const DATA = @json($data);
	{{-- Comentario de Blade, no de JS: lo escrito con // llega al navegador del
	     cliente. Las columnas de importe vienen del servidor según la vista. --}}
	const IMPORTES = @json($importes);

	const CH = {
		meta:{color:'#1877F2'}, google:{color:'#EA4335'}, tiktok:{color:'#111827'},
		linkedin:{color:'#0A66C2'}, spotify:{color:'#1DB954'}, openai:{color:'#10A37F'},
	};
	const PALETA = ['#638E76','#C2703D','#3D6FC2','#B23A6B','#8A6FBF','#3F8F8A','#C2A03D','#9A5B3D'];
	const clientesOrden = Object.keys(DATA.clientes);

	const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
	const nombre = (dim,v) => dim==='p' ? (DATA.nombres[v] || v) : (DATA.clientes[v]?.n || v);
	const color  = (dim,v) => dim==='p'
		? (CH[v]?.color || '#638E76')
		: (DATA.clientes[v]?.color || PALETA[clientesOrden.indexOf(v) % PALETA.length]);

	const eur = v => (Math.round(v*100)/100).toLocaleString('es-ES',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' €';
	const n0  = v => Math.round(v).toLocaleString('es-ES');
	const pct = v => (Math.round(v*10)/10).toLocaleString('es-ES') + '%';
	const parse = s => new Date(s+'T00:00:00');
	const iso = d => d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
	const addDays = (s,n) => { const d=parse(s); d.setDate(d.getDate()+n); return iso(d); };

	{{-- La columna principal (ordena y reparte el % del total) es 'c', lo
	     facturado, que existe en las dos vistas. --}}
	const PRINCIPAL = 'c';
	const FMT = {eur, pct, n0};

	const S = { from:DATA.min, to:DATA.max, compare:false, group:'p', cliente:'all', canal:'all',
		sort:PRINCIPAL, dir:'desc', metric:PRINCIPAL, paso:'d' };

	function rowsIn(from,to){
		return DATA.rows.filter(r => r.f>=from && r.f<=to
			&& (S.cliente==='all' || r.cl===S.cliente)
			&& (S.canal==='all' || r.p===S.canal));
	}

	// Las métricas derivadas no se suman: se acumulan los crudos y se divide
	// al final.
	function agg(rows){
		const a={c:0,g:0,k:0,l:0,i:0};
		for(const r of rows){a.c+=r.c;a.g+=(r.g||0);a.k+=r.k;a.l+=r.l;a.i+=r.i;}
		return derivadas(a);
	}
	function derivadas(a){
		a.m   = a.c - a.g;
		a.mp  = a.g ? a.m/a.g*100 : 0;
		a.cpc = a.k ? a.c/a.k : 0;
		a.cpl = a.l ? a.c/a.l : 0;
		return a;
	}
	function prevRange(from,to){
		const days = Math.round((parse(to)-parse(from))/86400000)+1;
		return [addDays(from,-days), addDays(from,-1)];
	}

	function renderKPIs(){
		const cur = agg(rowsIn(S.from,S.to));
		let prev = null;
		if(S.compare){ const [pf,pt]=prevRange(S.from,S.to); prev=agg(rowsIn(pf,pt)); }

		const defs = IMPORTES.map(([k,lab,f],n) => [lab, k, FMT[f], n===0 ? n0(cur.i)+' impresiones' : '&nbsp;']);
		defs.push(['Clics','k',n0,'CPC '+eur(cur.cpc)]);
		defs.push(['Leads','l',n0,'CPL '+eur(cur.cpl)]);

		document.getElementById('kpis').innerHTML = defs.map(([lab,key,fmt,sub])=>{
			let d='';
			if(prev){
				const a=cur[key], b=prev[key];
				if(b>0){
					const p=(a-b)/b*100;
					const cls = Math.abs(p)<0.05 ? 'flat' : (p>0?'up':'down');
					d=`<div class="delta ${cls}">${p>0?'▲':(p<0?'▼':'■')} ${Math.abs(Math.round(p*10)/10).toLocaleString('es-ES')}% vs periodo anterior</div>`;
				}
			}
			const neg = cur[key]<0 ? ' neg' : '';
			return `<div class="kpi"><div class="kpi__label">${esc(lab)}</div><div class="kpi__num${neg}">${fmt(cur[key])}</div><div class="kpi__sub">${sub}</div>${d}</div>`;
		}).join('');

		const filtros = [];
		if(S.cliente!=='all') filtros.push(nombre('cl',S.cliente));
		if(S.canal!=='all') filtros.push(nombre('p',S.canal));
		document.getElementById('periodLabel').textContent =
			'Periodo: '+(S.from===S.to?S.from:S.from+' → '+S.to)+' · '+(filtros.length?filtros.join(' · '):'todo');
	}

	function columnas(){
		const cols = IMPORTES.map(([k,lab,f]) => [k,lab,FMT[f]]);
		cols.push(['share','% del total',null],['i','Impresiones',n0],['k','Clics',n0],['l','Leads',n0],['cpc','CPC',eur],['cpl','CPL',eur]);
		return cols;
	}

	function grupos(){
		const map={};
		for(const r of rowsIn(S.from,S.to)){
			const v=r[S.group];
			(map[v]=map[v]||[]).push(r);
		}
		const tot = agg(Object.values(map).flat());
		const arr = Object.entries(map).map(([v,rows])=>{
			const a=agg(rows); a.v=v; a.share = tot[PRINCIPAL] ? a[PRINCIPAL]/tot[PRINCIPAL]*100 : 0; return a;
		});
		const key = S.sort;
		arr.sort((a,b)=>{const d=b[key]-a[key]; return S.dir==='desc'?d:-d;});
		return {arr, tot};
	}

	// Pinchar en una fila filtra por ella y cambia a la otra dimensión: de
	// "Meta" a qué clientes gastan en Meta, y al revés.
	const PUEDE_PROFUNDIZAR = !!document.getElementById('groupBy');

	function renderTable(){
		const cols = columnas();
		document.getElementById('tablaTitulo').textContent = S.group==='p' ? 'Por canal' : 'Por cliente';
		document.getElementById('drillHint').textContent = PUEDE_PROFUNDIZAR
			? (S.group==='p' ? 'Pincha un canal para ver sus clientes' : 'Pincha un cliente para ver sus canales') : '';

		document.getElementById('thead').innerHTML =
			`<th class="no">${S.group==='p'?'Canal':'Cliente'}</th>` +
			cols.map(([k,lab])=>`<th data-k="${k}" class="${S.sort===k?'act':''}">${esc(lab)} <span class="ar">${S.sort===k&&S.dir==='asc'?'▴':'▾'}</span></th>`).join('');
		document.querySelectorAll('#thead th[data-k]').forEach(th=>th.onclick=()=>{
			const k=th.dataset.k; if(S.sort===k){S.dir=S.dir==='desc'?'asc':'desc';}else{S.sort=k;S.dir='desc';} renderTable();
		});

		const {arr,tot} = grupos();
		const celda = (o,[k,,fmt]) => {
			if(k==='share') return `<td>${pct(o.share)}<span class="share"><span style="width:${Math.max(0,Math.min(100,o.share))}%"></span></span></td>`;
			const cls = o[k]<0 ? 'neg' : (k===PRINCIPAL ? 'val' : '');
			return `<td class="${cls}">${fmt(o[k])}</td>`;
		};
		const tb=document.getElementById('tbody');
		tb.innerHTML = arr.length ? arr.map(o=>{
			const c=color(S.group,o.v);
			return `<tr class="${PUEDE_PROFUNDIZAR?'drill':''}" data-v="${esc(o.v)}" style="border-left-color:${c}">
				<td><span class="cdot"><span class="dot" style="background:${c}"></span>${esc(nombre(S.group,o.v))}</span></td>
				${cols.map(col=>celda(o,col)).join('')}</tr>`;
		}).join('') : `<tr><td colspan="${cols.length+1}" class="empty">Sin datos para estos filtros.</td></tr>`;

		tot.share = arr.length ? 100 : 0;
		document.getElementById('tfoot').innerHTML = arr.length>1
			? `<tr><td>Total</td>${cols.map(col=>celda(tot,col)).join('')}</tr>` : '';

		if(PUEDE_PROFUNDIZAR){
			tb.querySelectorAll('tr.drill').forEach(tr=>tr.onclick=()=>{
				if(S.group==='p'){ S.canal=tr.dataset.v; setGroup('cl'); }
				else { S.cliente=tr.dataset.v; setGroup('p'); }
				syncFiltros(); renderAll();
			});
		}
	}

	// Agrupa fechas en días, semanas (empiezan en lunes) o meses.
	function cubo(f){
		if(S.paso==='m') return f.slice(0,7);
		if(S.paso==='s'){ const d=parse(f); d.setDate(d.getDate()-((d.getDay()+6)%7)); return iso(d); }
		return f;
	}
	function etiqueta(b){
		if(S.paso==='m'){ const [y,m]=b.split('-'); return new Date(y,m-1,1).toLocaleDateString('es-ES',{month:'short',year:'2-digit'}); }
		return (S.paso==='s'?'sem. ':'')+b.slice(8,10)+'/'+b.slice(5,7);
	}

	let chart=null;
	function renderChart(){
		const buckets=[];
		for(let f=S.from; f<=S.to; f=addDays(f,1)){ const b=cubo(f); if(buckets[buckets.length-1]!==b) buckets.push(b); }

		const acc={};
		for(const r of rowsIn(S.from,S.to)){
			const s=r[S.group], b=cubo(r.f);
			const o=((acc[s]=acc[s]||{})[b]=acc[s][b]||{c:0,g:0,k:0,l:0,i:0});
			o.c+=r.c; o.g+=(r.g||0); o.k+=r.k; o.l+=r.l; o.i+=r.i;
		}
		const M = S.metric;
		const vacio = {c:0,g:0,k:0,l:0,i:0};
		const series = Object.keys(acc).sort((a,b)=>{
			const t=s=>Object.values(acc[s]).reduce((x,o)=>x+o.c,0); return t(b)-t(a);
		});
		const fmt = ['k','l','i'].includes(M) ? n0 : eur;
		const datasets = series.map(s=>({
			label:nombre(S.group,s), backgroundColor:color(S.group,s),
			data:buckets.map(b=>Math.round(derivadas({...(acc[s][b]||vacio)})[M]*100)/100),
		}));
		if(chart) chart.destroy();
		chart=new Chart(document.getElementById('chart'),{type:'bar',data:{labels:buckets.map(etiqueta),datasets},options:{
			responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
			plugins:{legend:{position:'bottom'},tooltip:{callbacks:{
				label:c=>c.dataset.label+': '+fmt(c.parsed.y),
				footer:items=>'Total: '+fmt(items.reduce((t,i)=>t+i.parsed.y,0)),
			}}},
			scales:{x:{stacked:true,grid:{display:false}},y:{stacked:true,grid:{color:'#f0eeec'},ticks:{callback:v=>fmt(v)}}}
		}});
	}

	function renderAll(){ renderKPIs(); renderTable(); renderChart(); }

	function setGroup(g){
		S.group=g;
		document.querySelectorAll('#groupBy button').forEach(b=>b.classList.toggle('on', b.dataset.g===g));
	}
	function syncFiltros(){
		const fc=document.getElementById('fCliente'); if(fc) fc.value=S.cliente;
		document.getElementById('fCanal').value=S.canal;
	}

	// Presets
	const clamp = s => s<DATA.min?DATA.min:(s>DATA.max?DATA.max:s);
	function applyPreset(p){
		const max=DATA.max;
		if(p==='7'){S.from=clamp(addDays(max,-6));S.to=max;}
		else if(p==='30'){S.from=clamp(addDays(max,-29));S.to=max;}
		else if(p==='month'){S.from=clamp(max.slice(0,8)+'01');S.to=max;}
		else if(p==='prevmonth'){const d=parse(max.slice(0,8)+'01');d.setMonth(d.getMonth()-1);const end=new Date(d.getFullYear(),d.getMonth()+1,0);S.from=clamp(iso(d));S.to=clamp(iso(end));}
		else {S.from=DATA.min;S.to=DATA.max;}
		document.getElementById('from').value=S.from; document.getElementById('to').value=S.to; renderAll();
	}

	// Init
	document.getElementById('year').textContent=new Date().getFullYear();
	const fCanal=document.getElementById('fCanal');
	for(const p of DATA.canales){ const o=document.createElement('option'); o.value=p; o.textContent=nombre('p',p); fCanal.appendChild(o); }
	fCanal.onchange=e=>{S.canal=e.target.value;renderAll();};
	const fCliente=document.getElementById('fCliente');
	if(fCliente) fCliente.onchange=e=>{S.cliente=e.target.value;renderAll();};
	document.querySelectorAll('#groupBy button').forEach(b=>b.onclick=()=>{setGroup(b.dataset.g);renderAll();});

	const metric=document.getElementById('metric');
	for(const [k,lab] of [...IMPORTES.filter(([,,f])=>f==='eur'),['k','Clics'],['l','Leads'],['i','Impresiones'],['cpc','CPC'],['cpl','CPL']]){
		const o=document.createElement('option'); o.value=k; o.textContent=lab; metric.appendChild(o);
	}
	metric.value=S.metric;
	metric.onchange=e=>{S.metric=e.target.value;renderChart();};
	document.getElementById('paso').onchange=e=>{S.paso=e.target.value;renderChart();};

	for(const id of ['from','to']){
		const el=document.getElementById(id); el.value=S[id]; el.min=DATA.min; el.max=DATA.max;
		el.onchange=e=>{S[id]=e.target.value;document.querySelectorAll('#presets button').forEach(x=>x.classList.remove('on'));renderAll();};
	}
	document.querySelectorAll('#presets button').forEach(b=>b.onclick=()=>{
		document.querySelectorAll('#presets button').forEach(x=>x.classList.remove('on')); b.classList.add('on'); applyPreset(b.dataset.p);
	});
	document.getElementById('compare').onchange=e=>{S.compare=e.target.checked;renderKPIs();};

	// CSV de la tabla tal como se ve (agrupación, filtros y orden).
	document.getElementById('btnCsv').onclick=()=>{
		const cols=columnas();
		const {arr,tot}=grupos(); tot.share=100;
		const dec=v=>(Math.round(v*100)/100).toFixed(2).replace('.',',');
		const q=s=>'"'+String(s).replace(/"/g,'""')+'"';
		const fila=(lab,o)=>[lab,...cols.map(([k,,fmt])=>fmt===n0?Math.round(o[k]):dec(o[k]))].map(q).join(';');
		const lines=[[S.group==='p'?'Canal':'Cliente',...cols.map(([,lab])=>lab)].map(q).join(';')];
		for(const o of arr) lines.push(fila(nombre(S.group,o.v),o));
		lines.push(fila('Total',tot));
		const blob=new Blob(['﻿'+lines.join('\r\n')],{type:'text/csv;charset=utf-8'});
		const a=document.createElement('a');
		a.href=URL.createObjectURL(blob);
		a.download='gastos-globales_'+(S.group==='p'?'por-canal':'por-cliente')+'_'+S.from+'_'+S.to+'.csv';
		document.body.appendChild(a); a.click(); a.remove();
	};
	document.getElementById('btnPdf').onclick=()=>window.print();

	renderAll();
	</script>
</body>
</html>
