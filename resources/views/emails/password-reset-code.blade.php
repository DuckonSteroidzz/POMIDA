<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Password reset code</title>
</head>
<body style="margin:0;padding:24px;background:#FDE8DE;font-family:Arial,Helvetica,sans-serif;color:#5A2920;">

    <div style="max-width:520px;margin:0 auto;background:#FFFDF9;border-radius:16px;padding:32px;">

        <h1 style="margin:0 0 8px;font-size:22px;color:#8B1A1A;">
            Peachy Cakes &amp; Deli Cafe
        </h1>

        <p style="margin:0 0 24px;font-size:14px;color:#8A6A61;">
            Password reset request
        </p>

        <p style="font-size:15px;line-height:1.6;">
            Hi {{ $user->name }},
        </p>

        <p style="font-size:15px;line-height:1.6;">
            Use the verification code below to reset your password.
        </p>

        <p style="margin:24px 0;text-align:center;">
            <span style="display:inline-block;padding:14px 28px;border-radius:12px;background:#8B1A1A;color:#ffffff;font-size:30px;font-weight:bold;letter-spacing:8px;">
                {{ $code }}
            </span>
        </p>

        <p style="font-size:14px;line-height:1.6;color:#8A6A61;">
            This code expires in {{ $minutes }} minutes. If you did not request a
            password reset, you can safely ignore this email — your password will
            not change.
        </p>

    </div>

</body>
</html>
