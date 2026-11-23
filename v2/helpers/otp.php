<?php

// 6-digit registration / reset codes.
//
// Dev/staging shortcut: when OTP_DEFAULT_CODE is set in .env (exactly 6 digits, e.g. 123456) every
// OTP is that code and no WhatsApp message is sent, so testers on aluria-dev.getmovira.com don't need
// a real phone. It is driven ONLY by the server's own .env — never by request data such as the Origin
// header, which a client could forge — so production stays safe as long as the variable is unset there.
function devDefaultOtp(): ?string {
    static $code = false;
    if ($code === false) {
        $raw  = trim((string)($_ENV['OTP_DEFAULT_CODE'] ?? getenv('OTP_DEFAULT_CODE') ?: ''));
        $code = preg_match('/^[0-9]{6}$/', $raw) ? $raw : null;
    }
    return $code;
}

function generateOtpCode(): string {
    return devDefaultOtp() ?? str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}
