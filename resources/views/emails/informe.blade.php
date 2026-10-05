<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $asunto }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f6f8;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6f8;padding:24px 12px;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border-radius:8px;overflow:hidden;">
        <tr>
          <td style="padding:20px 24px;border-bottom:4px solid {{ $color ?: '#111827' }};">
            <img src="https://olimedia.es/wp-content/themes/olimedia/assets/img/olimedia-logo.png" alt="OliMedia" height="32" style="display:block;height:32px;">
          </td>
        </tr>
        <tr>
          <td style="padding:24px;font-size:15px;line-height:1.5;">
            {{-- El cuerpo sale de PlantillaEmail::render(): variables ya escapadas. --}}
            {!! $cuerpo !!}
          </td>
        </tr>
        <tr>
          <td style="padding:16px 24px;background:#f9fafb;font-size:12px;color:#6b7280;">
            Informe automático de OliMedia · {{ parse_url(config('app.url'), PHP_URL_HOST) }}
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
