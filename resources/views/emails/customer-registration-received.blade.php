<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Registrasi Diterima</title>
</head>
<body style="font-family: DejaVu Sans, Arial, sans-serif; font-size: 14px; color: #333; line-height: 1.6;">
    <p>Yth. {{ $user->name }},</p>
    <p>
        Terima kasih telah mendaftar sebagai customer SOL Logistics untuk perusahaan
        <strong>{{ $company->name }}</strong> (Customer Code: <strong>{{ $company->company_code }}</strong>).
    </p>
    <p>
        Registrasi Anda telah kami terima dan saat ini berstatus <strong>Pending Review</strong>.
        Tim internal kami akan melakukan verifikasi data dalam 1–2 hari kerja.
    </p>
    <p>
        Setelah disetujui, akun admin Anda akan diaktifkan dan Anda dapat login ke portal customer.
    </p>
    <p style="color: #666; font-size: 12px; margin-top: 24px;">
        Email ini dikirim otomatis. Mohon tidak membalas email ini.
    </p>
</body>
</html>
