{{--
    Every error page: plain words for staff and a big button back to the right place.
    Kept free of the compiled front-end and of anything that could fail itself (database, Vite),
    because it is also shown when those are the problem.
--}}
@php
    $code = (int) ($code ?? 500);
    $copyKey = in_array($code, [403, 404, 419, 429, 500, 503], true) ? $code : ($code >= 500 ? 500 : 404);
    $title = __("errors.pages.{$copyKey}.title");
    $body = __("errors.pages.{$copyKey}.body");

    // Our own refusal messages (for example the kitchen screen's) are written for staff; framework defaults are not.
    $detail = null;
    if ($code === 403 && isset($exception)) {
        $message = trim((string) $exception->getMessage());
        if ($message !== '' && ! in_array($message, ['Forbidden', 'This action is unauthorized.'], true)) {
            $detail = $message;
        }
    }

    $primary = ['url' => url('/pos/login'), 'label' => __('errors.actions.sign_in')];
    $signOut = null;
    try {
        $backOffice = request()->is(trim(config('rms.admin_path', 'admin'), '/').'*', trim(config('rms.hq_path', 'hq'), '/').'*');
        $user = $code === 419 ? null : auth()->user();

        if ($code === 419) {
            $primary = ['url' => $backOffice ? url('/'.trim(config('rms.admin_path', 'admin'), '/').'/login') : route('pos.login'), 'label' => __('errors.actions.sign_in_again')];
        } elseif ($user && $backOffice && $user->can(\App\Modules\Staff\Enums\Permission::AdminAccess->value)) {
            $primary = ['url' => url('/'.trim(config('rms.admin_path', 'admin'), '/')), 'label' => __('errors.actions.back_office')];
        } elseif ($user && $user->can(\App\Modules\Staff\Enums\Permission::PosAccess->value)) {
            $primary = ['url' => route('pos.home'), 'label' => __('errors.actions.till')];
        } elseif ($user || ! $backOffice) {
            $primary = ['url' => route('pos.login'), 'label' => __('errors.actions.sign_in')];
        } else {
            $primary = ['url' => url('/'.trim(config('rms.admin_path', 'admin'), '/').'/login'), 'label' => __('errors.actions.sign_in')];
        }

        if ($code === 403 && $user) {
            $signOut = ['url' => route('pos.logout'), 'token' => csrf_token()];
        }
    } catch (\Throwable) {
        // The page must always render: fall back to the till sign-in.
    }
    $retry = in_array($code, [429, 500, 503], true);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if ($code === 503)<meta http-equiv="refresh" content="30">@endif
    <title>{{ $title }} · {{ config('rms.product_name', config('app.name')) }}</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:#020617;color:#e2e8f0;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
        main{width:100%;max-width:560px;text-align:center}
        .code{display:inline-block;margin-bottom:16px;padding:4px 12px;border-radius:999px;background:#1e293b;color:#94a3b8;font-size:13px;font-weight:600;letter-spacing:.05em}
        h1{margin:0 0 12px;font-size:28px;line-height:1.2;color:#f8fafc}
        p{margin:0 0 12px;color:#cbd5e1}
        .detail{margin:0 0 12px;padding:12px 16px;border-radius:12px;background:rgba(244,63,94,.15);color:#fda4af;font-weight:600}
        .actions{display:flex;flex-direction:column;gap:12px;margin-top:28px}
        a.btn,button.btn{display:flex;align-items:center;justify-content:center;min-height:56px;padding:0 24px;border:0;border-radius:16px;font:700 18px system-ui,sans-serif;text-decoration:none;cursor:pointer}
        .primary{background:#10b981;color:#020617}
        .secondary{background:#1e293b;color:#e2e8f0;width:100%}
        .brand{margin-top:32px;font-size:13px;color:#64748b}
    </style>
</head>
<body>
    <main>
        <span class="code">{{ $code }}</span>
        <h1>{{ $title }}</h1>
        <p>{{ $body }}</p>
        @if ($detail)<p class="detail" role="alert">{{ $detail }}</p>@endif
        <div class="actions">
            <a class="btn primary" href="{{ $primary['url'] }}">{{ $primary['label'] }}</a>
            @if ($retry)<a class="btn secondary" href="{{ url()->current() }}">{{ __('errors.actions.retry') }}</a>@endif
            @if ($signOut)
                <form method="post" action="{{ $signOut['url'] }}">
                    <input type="hidden" name="_token" value="{{ $signOut['token'] }}">
                    <button type="submit" class="btn secondary">{{ __('errors.actions.switch_user') }}</button>
                </form>
            @endif
        </div>
        <p class="brand">{{ config('rms.product_name', config('app.name')) }}</p>
    </main>
</body>
</html>
