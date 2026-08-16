<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Registrasi Ditolak</title>
</head>
<body style="font-family: DejaVu Sans, Arial, sans-serif; font-size: 14px; color: #333; line-height: 1.6;">
    <p>Yth. {{ $user->name }},</p>
    <p>
        Registrasi customer untuk perusahaan <strong>{{ $company->name }}</strong>
        (Customer Code: <strong>{{ $company->company_code }}</strong>) <strong>belum dapat kami setujui</strong>.
    </p>
    <p><strong>Alasan penolakan:</strong></p>
    <p style="background: #f8f8f8; border-left: 4px solid #dc2626; padding: 12px 16px; margin: 12px 0;">
        {{ $reason }}
    </p>
    <p>
        Jika Anda memiliki pertanyaan atau ingin mengajukan ulang dengan data yang diperbarui,
        silakan hubungi tim SOL Logistics melalui email perusahaan kami.
    </p>
    <p style="color: #666; font-size: 12px; margin-top: 24px;">
        Email ini dikirim otomatis. Mohon tidak membalas email ini.
    </p>
</body>
</html>
