<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>HolidayGoGoGo | Two-Factor Authentication</title>
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
            <div class="login-form p-7 position-relative overflow-hidden">
                <div class="d-flex flex-center mb-10">
                    <a>
                        <img src="<?php echo base_url('assets/image/logo.png'); ?>" class="max-h-75px">
                    </a>
                </div>
                <div class="login-signin">
                    <div class="text-center mb-8">
                        <h3 class="font-weight-bold">Two-Factor Authentication</h3>
                        <p class="text-muted font-weight-bold">Enter the 6-digit code from your Google Authenticator app.</p>
                    </div>
                    <?php $locked = isset($locked_seconds) ? (int) $locked_seconds : 0; ?>
                    <form action="<?php echo base_url('Login/Two_Factor') ?>" method="post" class="form">
                        <div class="form-group mb-5">
                            <label>Authentication Code</label>
                            <div class="input-icon">
                                <input required <?php echo $locked ? 'disabled' : 'autofocus'; ?> type="text" name="code" id="totp-code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" placeholder="123456" class="form-control text-center" style="letter-spacing:8px;font-size:1.4rem;">
                                <span>
                                    <i class="la la-mobile-phone"></i>
                                </span>
                            </div>
                        </div>
                        <div class="text-center mt-10">
                            <input type="submit" id="totp-verify" name="verify" value="Verify" <?php echo $locked ? 'disabled' : ''; ?> class="btn btn-primary font-weight-bold px-9 py-4 my-3 mx-4" style="width:180px;">
                            <?php if(isset($error_message)) { ?>
                                <br><br>
                                <div class="alert alert-custom alert-light-danger fade show mb-5">
                                    <div class="alert-text"><?php echo $error_message; ?></div>
                                </div>
                            <?php } ?>
                            <div class="mt-5">
                                <a href="<?php echo base_url('Login/Logout') ?>" class="text-muted font-weight-bold">Cancel &amp; sign in again</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
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

        // While locked, keep the field + button disabled and count down; reload
        // when the lock expires so the server re-renders the form unlocked.
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
