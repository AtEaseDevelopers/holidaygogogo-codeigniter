<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>HolidayGoGoGo | Set Up Two-Factor Authentication</title>
    <link href="<?php echo base_url('assets/image/favicon.png'); ?>" rel="icon">
    <link href="<?php echo base_url('assets/image/favicon.png'); ?>" rel="apple-touch-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/login.css'); ?>" type="text/css" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/plugins-bundle.css'); ?>" type="text/css" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/prismjs-bundle.css'); ?>" type="text/css" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/style-bundle.css'); ?>" type="text/css" rel="stylesheet">
</head>

<body>
    <div class="login login-2 login-signin-on d-flex flex-row-fluid">
        <div class="d-flex flex-center flex-row-fluid bgi-size-cover bgi-position-top bgi-no-repeat" style="background-image:url(<?php echo base_url('assets/image/login.jpg'); ?>);">
            <div class="login-form p-7 position-relative overflow-hidden" style="width:420px;max-width:92vw;">
                <div class="d-flex flex-center mb-8">
                    <a>
                        <img src="<?php echo base_url('assets/image/logo.png'); ?>" class="max-h-75px">
                    </a>
                </div>
                <div class="login-signin">
                    <div class="text-center mb-6">
                        <h3 class="font-weight-bold">Set Up Two-Factor</h3>
                        <p class="text-muted font-weight-bold">Scan this QR code with Google Authenticator (or Authy / Microsoft Authenticator), then enter the 6-digit code it shows.</p>
                    </div>

                    <?php if(isset($otpauth)) { ?>
                        <div class="d-flex flex-center mb-5">
                            <div id="totp-qr" style="padding:10px;background:#fff;border-radius:6px;"></div>
                        </div>
                        <div class="text-center mb-6">
                            <p class="text-muted font-weight-bold mb-2" style="font-size:.9rem;">Can't scan? Enter this key manually:</p>
                            <div class="d-inline-flex align-items-center">
                                <code id="totp-secret" style="font-size:1rem;letter-spacing:2px;word-break:break-all;"><?php echo htmlspecialchars($secret_b32); ?></code>
                                <button type="button" id="totp-copy" class="btn btn-icon btn-light-primary btn-sm ml-2" style="width:28px;height:28px;" data-toggle="tooltip" title="Copy key">
                                    <i class="la la-copy" style="font-size:15px;"></i>
                                </button>
                            </div>
                        </div>

                        <?php $locked = isset($locked_seconds) ? (int) $locked_seconds : 0; ?>
                        <form action="<?php echo base_url('Login/Setup_Two_Factor') ?>" method="post" class="form">
                            <div class="form-group mb-5">
                                <label>Verification Code</label>
                                <div class="input-icon">
                                    <input required <?php echo $locked ? 'disabled' : 'autofocus'; ?> type="text" name="code" id="totp-code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" placeholder="123456" class="form-control text-center" style="letter-spacing:8px;font-size:1.4rem;">
                                    <span>
                                        <i class="la la-mobile-phone"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="text-center mt-8">
                                <input type="submit" id="totp-verify" name="verify" value="Verify &amp; Enable" <?php echo $locked ? 'disabled' : ''; ?> class="btn btn-primary font-weight-bold px-9 py-4 my-3" style="width:220px;">
                            </div>
                        </form>
                    <?php } ?>

                    <?php if(isset($error_message)) { ?>
                        <div class="alert alert-custom alert-light-danger fade show mb-5 mt-3">
                            <div class="alert-text"><?php echo $error_message; ?></div>
                        </div>
                    <?php } ?>

                    <div class="text-center mt-5">
                        <a href="<?php echo base_url('Login/Logout') ?>" class="text-muted font-weight-bold">Cancel &amp; sign in again</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if(isset($otpauth)) { ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        // Rendered entirely in the browser from the otpauth text — the secret is
        // drawn locally and never sent to any third-party service.
        new QRCode(document.getElementById("totp-qr"), {
            text: <?php echo json_encode($otpauth); ?>,
            width: 200,
            height: 200,
            correctLevel: QRCode.CorrectLevel.M
        });
    </script>
    <?php } ?>

    <script>
        // Copy the manual key to the clipboard. Uses the async Clipboard API when
        // available (HTTPS / localhost), else falls back to execCommand so it
        // still works on plain-http hosts like *.test.
        (function () {
            var btn = document.getElementById('totp-copy');
            var secretEl = document.getElementById('totp-secret');
            if (!btn || !secretEl) { return; }
            function flash(ok) {
                var icon = btn.querySelector('i');
                var prev = icon.className;
                icon.className = ok ? 'la la-check' : 'la la-times';
                if (window.jQuery) { jQuery(btn).attr('data-original-title', ok ? 'Copied!' : 'Press Ctrl/Cmd+C').tooltip('show'); }
                setTimeout(function () {
                    icon.className = prev;
                    if (window.jQuery) { jQuery(btn).attr('data-original-title', 'Copy key'); }
                }, 1500);
            }
            btn.addEventListener('click', function () {
                var text = secretEl.textContent.trim();
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(function () { flash(true); }, function () { flash(false); });
                    return;
                }
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                var ok = false;
                try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                document.body.removeChild(ta);
                flash(ok);
            });
            if (window.jQuery) { jQuery('[data-toggle="tooltip"]').tooltip(); }
        })();

        // Code field accepts digits only; Verify stays disabled until exactly 6
        // digits are entered. Does not touch the button while locked.
        (function () {
            var input = document.getElementById('totp-code');
            var btn = document.getElementById('totp-verify');
            var locked = <?php echo (isset($locked_seconds) && (int) $locked_seconds > 0) ? 'true' : 'false'; ?>;
            if (!input || !btn) { return; }
            function sync() {
                input.value = input.value.replace(/\D/g, '').slice(0, 6);
                if (!locked) { btn.disabled = input.value.length !== 6; }
            }
            input.addEventListener('input', sync);
            sync();
        })();

        // While locked, count down and reload when the lock expires.
        (function () {
            var remaining = <?php echo isset($locked_seconds) ? (int) $locked_seconds : 0; ?>;
            if (remaining <= 0) { return; }
            var alertText = document.querySelector('.alert-text');
            function tick() {
                if (remaining <= 0) { window.location.reload(); return; }
                var m = Math.floor(remaining / 60), s = remaining % 60;
                if (alertText) {
                    alertText.textContent = 'Too many attempts. Try again in ' + m + ':' + (s < 10 ? '0' : '') + s;
                }
                remaining--;
                setTimeout(tick, 1000);
            }
            tick();
        })();
    </script>
</body>

</html>
