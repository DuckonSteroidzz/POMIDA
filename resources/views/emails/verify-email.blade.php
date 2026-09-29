<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Confirm your email</title>
</head>
<body style="margin:0;padding:24px;background:#FDE8DE;font-family:Arial,Helvetica,sans-serif;color:#5A2920;">

    <div style="max-width:520px;margin:0 auto;background:#FFFDF9;border-radius:16px;padding:32px;">

        <h1 style="margin:0 0 8px;font-size:22px;color:#8B1A1A;">
            Peachy Cakes &amp; Deli Cafe
        </h1>

        <p style="margin:0 0 24px;font-size:14px;color:#8A6A61;">
            Confirm your email address
        </p>

        <p style="font-size:15px;line-height:1.6;">
            Hi {{ $user->name }},
        </p>

        <p style="font-size:15px;line-height:1.6;">
            Thanks for creating a Peachy Cakes account. Please confirm this is
            your email address by clicking the button below.
        </p>

        <p style="margin:24px 0;text-align:center;">
            <a href="{{ $verificationUrl }}" style="display:inline-block;padding:14px 28px;border-radius:12px;background:#8B1A1A;color:#ffffff;font-size:16px;font-weight:bold;text-decoration:none;">
                Confirm Email Address
            </a>
        </p>

        <p style="font-size:14px;line-height:1.6;color:#8A6A61;">
            This link expires in 60 minutes. If you did not create this
            account, you can safely ignore this email — your account will
            still work, it will just stay unverified.
        </p>

    </div>

</body>
</html>
