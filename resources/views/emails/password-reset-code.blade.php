<!doctype html>
<html>
<body style="margin:0;padding:24px;background:#f7efe0;font-family:Georgia,serif;color:#2b2118">
  <table role="presentation" width="100%" style="max-width:480px;margin:0 auto;background:#fffaf0;border-radius:16px;border:1px solid #e2d3b8">
    <tr><td style="padding:24px 28px;background:#6e1423;border-radius:16px 16px 0 0;color:#f3e2c0;font-size:20px">{{ $app }}</td></tr>
    <tr><td style="padding:28px">
      <p style="margin:0 0 12px">Namaste {{ $devotee->name }},</p>
      <p style="margin:0 0 20px">Use this code in the app to set a new password:</p>
      <p style="margin:0 0 20px;font-size:34px;letter-spacing:10px;font-weight:bold;color:#6e1423;text-align:center">{{ $code }}</p>
      <p style="margin:0 0 8px;font-size:14px;color:#6b5a48">It expires in {{ $minutes }} minutes.</p>
      <p style="margin:0;font-size:14px;color:#6b5a48">If you did not ask for this, you can ignore this email; your password has not changed.</p>
    </td></tr>
  </table>
</body>
</html>
