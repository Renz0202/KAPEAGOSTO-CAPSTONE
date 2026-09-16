<?php

function password_reset_email_html(
    string $recipientName,
    string $code,
    string $businessName,
    string $supportEmail = ''
): string {
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $recipient = $escape($recipientName !== '' ? $recipientName : 'there');
    $resetCode = $escape($code);
    $business = $escape($businessName !== '' ? $businessName : 'Kape Agosto');
    $support = $escape($supportEmail);
    $supportBlock = $supportEmail !== ''
        ? '<p style="margin:8px 0 0;color:#8a7468;font-size:12px;line-height:18px;">Need help? <a href="mailto:' . $support . '" style="color:#6f3318;text-decoration:underline;">' . $support . '</a></p>'
        : '';
    $year = date('Y');

    return '<!doctype html>'
        . '<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Password Reset Code</title></head>'
        . '<body style="margin:0;padding:0;background-color:#eee8e1;font-family:Arial,Helvetica,sans-serif;color:#38251d;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">Your ' . $business . ' password reset code expires in 15 minutes.</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background-color:#eee8e1;">'
        . '<tr><td align="center" style="padding:18px 12px;">'
        . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background-color:#ffffff;border:1px solid #dfd3c8;border-radius:16px;overflow:hidden;box-shadow:0 10px 28px rgba(61,35,24,.10);">'
        . '<tr><td align="center" style="padding:12px 24px 10px;background-color:#fffaf3;border-top:5px solid #8a431e;">'
        . '<img src="cid:kape-agosto-logo" width="150" alt="' . $business . '" style="display:block;width:150px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;">'
        . '</td></tr>'
        . '<tr><td align="center" style="padding:21px 30px 7px;">'
        . '<p style="margin:0 0 7px;color:#9a603e;font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">Account Security</p>'
        . '<h1 style="margin:0;color:#3a1c12;font-family:Georgia,Times New Roman,serif;font-size:28px;line-height:34px;font-weight:700;">Password Reset Code</h1>'
        . '<p style="margin:13px 0 0;color:#5f4a40;font-size:15px;line-height:22px;">Hello <strong style="color:#38251d;">' . $recipient . '</strong>,</p>'
        . '<p style="margin:6px auto 0;max-width:455px;color:#5f4a40;font-size:15px;line-height:22px;">Use the verification code below to create a new password for your ' . $business . ' account.</p>'
        . '</td></tr>'
        . '<tr><td align="center" style="padding:12px 30px 15px;">'
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="padding:13px 23px;background-color:#fff2d2;border:1px solid #e5c980;border-radius:12px;color:#32180f;font-family:Courier New,Courier,monospace;font-size:32px;line-height:38px;font-weight:700;letter-spacing:9px;">' . $resetCode . '</td></tr></table>'
        . '<p style="margin:9px 0 0;color:#7b665b;font-size:13px;line-height:19px;">This code expires in <strong style="color:#4a2b1d;">15 minutes</strong>.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:0 30px 20px;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background-color:#f8f4ef;border-left:4px solid #a65b30;border-radius:8px;">'
        . '<tr><td style="padding:11px 14px;color:#665249;font-size:13px;line-height:19px;"><strong style="color:#47291c;">Did not request this?</strong><br>You can safely ignore this email. Never share this code with anyone, including Kape Agosto staff.</td></tr>'
        . '</table></td></tr>'
        . '<tr><td align="center" style="padding:17px 24px 15px;background-color:#3a2018;">'
        . '<p style="margin:0 0 10px;color:#e9d8ca;font-size:12px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;">Connect with us</p>'
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr>'
        . '<td align="center" style="padding:0 9px;"><a href="https://www.facebook.com/kapeagosto" style="color:#ffffff;text-decoration:none;font-size:11px;line-height:16px;"><img src="https://img.icons8.com/color/48/facebook-new.png" width="28" height="28" alt="Facebook" style="display:block;margin:0 auto 4px;border:0;width:28px;height:28px;"><span style="color:#f7eee8;">Facebook</span></a></td>'
        . '<td align="center" style="padding:0 9px;"><a href="https://www.instagram.com/kapeAgosto" style="color:#ffffff;text-decoration:none;font-size:11px;line-height:16px;"><img src="https://img.icons8.com/fluency/48/instagram-new.png" width="28" height="28" alt="Instagram" style="display:block;margin:0 auto 4px;border:0;width:28px;height:28px;"><span style="color:#f7eee8;">Instagram</span></a></td>'
        . '<td align="center" style="padding:0 9px;"><a href="https://www.tiktok.com/@KapeAgosto" style="color:#ffffff;text-decoration:none;font-size:11px;line-height:16px;"><img src="https://img.icons8.com/color/48/tiktok--v1.png" width="28" height="28" alt="TikTok" style="display:block;margin:0 auto 4px;border:0;width:28px;height:28px;"><span style="color:#f7eee8;">TikTok</span></a></td>'
        . '</tr></table>'
        . $supportBlock
        . '<p style="margin:10px 0 0;color:#bfa99d;font-size:11px;line-height:16px;">&copy; ' . $year . ' ' . $business . '. All rights reserved.</p>'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}
