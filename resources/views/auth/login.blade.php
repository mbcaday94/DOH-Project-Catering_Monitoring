<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | DOH Catering Monitoring</title>
    <link rel="stylesheet" href="{{ asset('portal.css') }}">
    <script src="{{ asset('login.js') }}" defer></script>
</head>
<body class="login-page">
    <main class="portal">
        <aside class="identity" aria-label="Department of Health">
            <div class="agency">
                <img src="{{ asset('doh-seal.png') }}" alt="Department of Health seal" width="64" height="64">
                <div><span>Republic of the Philippines</span><strong>Department of Health</strong></div>
            </div>
            <p class="portal-name">Catering<br> Monitoring</p>
            <div class="identity-accent" aria-hidden="true"></div>
        </aside>
        <section class="access" aria-labelledby="login-title">
            <div class="form-wrap">
                <h1 id="login-title">Sign in</h1>
                <p class="intro">Use your assigned account to access the catering portal.</p>
                <form action="{{ route('login.store') }}" method="post">
                    @csrf
                    <div class="field">
                        <label for="login">Username or email address</label>
                        <input type="text" id="login" name="login" value="{{ old('login') }}" placeholder="Enter your username or email" autocomplete="username" autocapitalize="none" spellcheck="false" required maxlength="255"
                            @error('login') aria-invalid="true" aria-describedby="login-error" @enderror>
                        @error('login') <p class="error" id="login-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <div class="password-field">
                            <input type="password" id="password" name="password" autocomplete="current-password" required placeholder="Enter your password"
                                @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                            <button class="visibility" type="button" hidden aria-controls="password" aria-label="Show password">Show</button>
                        </div>
                        @error('password') <p class="error" id="password-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <label class="remember"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Remember me</label>
                    <button class="primary" type="submit">Sign in <span aria-hidden="true">→</span></button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
