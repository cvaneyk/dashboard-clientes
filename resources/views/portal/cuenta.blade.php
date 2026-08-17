<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Mi cuenta · OliMedia</title>
	<style>
		:root{--green:#638E76;--green-dark:#4f7361;--ink:#141414;--mut:#4b5563;--bg:#f6f4f2;--border:#e7e2de}
		*{box-sizing:border-box;margin:0;font-family:"Outfit",system-ui,-apple-system,"Segoe UI",sans-serif}
		body{background:var(--bg);min-height:100vh;display:grid;place-items:center;padding:24px;color:var(--ink)}
		.card{width:100%;max-width:440px;background:#fff;border:1px solid var(--border);border-radius:20px;padding:34px;box-shadow:0 30px 70px -40px rgba(20,20,20,.4)}
		.brand{font-size:1.5rem;font-weight:800;letter-spacing:-.02em}.brand b{color:var(--green)}
		.sub{color:var(--mut);font-size:.92rem;margin:2px 0 24px}
		h1{font-size:1.15rem;font-weight:800;margin-bottom:18px}
		label{display:block;font-size:.86rem;font-weight:600;margin:0 0 6px}
		input{width:100%;border:1.6px solid var(--border);border-radius:12px;padding:12px 14px;font-size:1rem;margin-bottom:16px}
		input:focus{outline:none;border-color:var(--green);box-shadow:0 0 0 4px #e8efe9}
		.btn{border:0;border-radius:999px;background:var(--green);color:#fff;font-weight:700;font-size:1rem;padding:13px 24px;cursor:pointer}
		.btn:hover{background:var(--green-dark)}
		.ok{background:#e8efe9;color:var(--green-dark);border:1px solid #cfe0d4;border-radius:10px;padding:11px 13px;font-size:.9rem;margin-bottom:16px}
		.err{background:#fdeceb;color:#b0432a;border:1px solid #f0c9c4;border-radius:10px;padding:11px 13px;font-size:.9rem;margin-bottom:16px}
		.back{display:inline-block;margin-top:16px;color:var(--mut);font-size:.9rem;text-decoration:underline}
	</style>
</head>
<body>
	<div class="card">
		<div class="brand">oli<b>media</b></div>
		<p class="sub">{{ $cliente?->nombre }}</p>
		<h1>Cambiar mi contraseña</h1>

		@if (session('ok'))
			<div class="ok">{{ session('ok') }}</div>
		@endif
		@if ($errors->any())
			<div class="err">{{ $errors->first() }}</div>
		@endif

		<form method="post" action="{{ route('portal.cuenta.update') }}">
			@csrf
			<label for="current">Contraseña actual</label>
			<input type="password" id="current" name="current" required>

			<label for="password">Nueva contraseña</label>
			<input type="password" id="password" name="password" required minlength="8">

			<label for="password_confirmation">Repite la nueva contraseña</label>
			<input type="password" id="password_confirmation" name="password_confirmation" required minlength="8">

			<button class="btn" type="submit">Guardar contraseña</button>
		</form>

		<a class="back" href="{{ route('portal.dashboard') }}">← Volver al dashboard</a>
	</div>
</body>
</html>
