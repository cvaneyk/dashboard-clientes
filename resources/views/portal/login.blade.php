<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Acceso · OliMedia</title>
	<style>
		:root{--green:#638E76;--green-dark:#4f7361;--ink:#141414;--bg:#f6f4f2;--border:#e7e2de}
		*{box-sizing:border-box;margin:0;font-family:"Outfit",system-ui,-apple-system,"Segoe UI",sans-serif}
		body{background:var(--bg);min-height:100vh;display:grid;place-items:center;padding:24px;color:var(--ink)}
		.card{width:100%;max-width:400px;background:#fff;border:1px solid var(--border);border-radius:20px;padding:38px 34px;box-shadow:0 30px 70px -40px rgba(20,20,20,.4)}
		.brand{font-size:1.6rem;font-weight:800;letter-spacing:-.02em;margin-bottom:4px}
		.brand b{color:var(--green)}
		.sub{color:#6b7280;font-size:.95rem;margin-bottom:26px}
		label{display:block;font-size:.88rem;font-weight:600;margin:0 0 6px}
		input{width:100%;border:1.6px solid var(--border);border-radius:12px;padding:12px 14px;font-size:1rem;margin-bottom:16px;transition:border-color .2s,box-shadow .2s}
		input:focus{outline:none;border-color:var(--green);box-shadow:0 0 0 4px #e8efe9}
		.btn{width:100%;border:0;border-radius:999px;background:var(--green);color:#fff;font-weight:700;font-size:1rem;padding:14px;cursor:pointer;transition:background .2s,transform .2s}
		.btn:hover{background:var(--green-dark);transform:translateY(-1px)}
		.err{background:#fdeceb;color:#b0432a;border:1px solid #f0c9c4;border-radius:10px;padding:10px 12px;font-size:.9rem;margin-bottom:16px}
		.rem{display:flex;align-items:center;gap:8px;font-size:.9rem;color:#6b7280;margin-bottom:20px}
		.rem input{width:auto;margin:0;accent-color:var(--green)}
	</style>
</head>
<body>
	<div class="card">
		<div class="brand"><img src="https://olimedia.es/wp-content/themes/olimedia/assets/img/olimedia-logo.png" alt="OliMedia Agencia de Marketing"></div>
		<p class="sub">Portal de clientes · Campañas</p>

		@if ($errors->any())
			<div class="err">{{ $errors->first() }}</div>
		@endif

		<form method="post" action="{{ route('portal.login.attempt') }}">
			@csrf
			<label for="email">Email</label>
			<input type="email" id="email" name="email" value="{{ old('email') }}" placeholder="tucorreo@empresa.com" required autofocus>

			<label for="password">Contraseña</label>
			<input type="password" id="password" name="password" placeholder="••••••••" required>

			<label class="rem"><input type="checkbox" name="remember" value="1"> Mantener sesión iniciada</label>

			<button class="btn" type="submit">Entrar</button>
		</form>
	</div>
</body>
</html>
