<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login - Kijumbe</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[radial-gradient(circle_at_top_left,_#21314f_0,_#0f172a_42%,_#f3efe8_42%)] text-slate-900">
    <div class="mx-auto flex min-h-screen max-w-7xl items-center px-4 py-10 sm:px-6 lg:px-8">
        <div class="grid w-full gap-6 lg:grid-cols-2">
            <section class="rounded-[36px] border border-white/20 bg-slate-950/90 p-8 text-white shadow-[0_30px_100px_rgba(15,23,42,.35)] backdrop-blur">
                <p class="text-xs uppercase tracking-[0.35em] text-white/50">Kijumbe Admin</p>
                <h1 class="mt-4 text-4xl font-semibold tracking-tight">Sign in to the dashboard</h1>
                <p class="mt-4 max-w-xl text-sm leading-7 text-white/65">
                    This console is restricted to system admins. Use the seeded admin account or your own admin credentials.
                </p>

                <div class="mt-8 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-[24px] bg-white/6 p-4 ring-1 ring-white/10">
                        <p class="text-xs uppercase tracking-[0.2em] text-white/45">Groups</p>
                        <p class="mt-3 text-3xl font-semibold">Live</p>
                        <p class="mt-2 text-sm text-white/60">Real data from the database</p>
                    </div>
                    <div class="rounded-[24px] bg-white/6 p-4 ring-1 ring-white/10">
                        <p class="text-xs uppercase tracking-[0.2em] text-white/45">Members</p>
                        <p class="mt-3 text-3xl font-semibold">Live</p>
                        <p class="mt-2 text-sm text-white/60">Shown inside each group</p>
                    </div>
                    <div class="rounded-[24px] bg-white/6 p-4 ring-1 ring-white/10">
                        <p class="text-xs uppercase tracking-[0.2em] text-white/45">Stats</p>
                        <p class="mt-3 text-3xl font-semibold">UI</p>
                        <p class="mt-2 text-sm text-white/60">Financial cards remain dummy</p>
                    </div>
                </div>
            </section>

            <section class="rounded-[36px] border border-white/50 bg-white/80 p-6 shadow-[0_30px_100px_rgba(15,23,42,.12)] backdrop-blur sm:p-8">
                <div class="max-w-md">
                    <div class="inline-flex items-center gap-2 rounded-full bg-slate-950 px-3 py-1 text-xs font-semibold tracking-[0.22em] text-white">
                        ADMIN ACCESS
                    </div>
                    <h2 class="mt-4 text-3xl font-semibold tracking-tight text-slate-950">Welcome back</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Enter your email and password to continue.</p>
                </div>

                @if ($errors->any())
                    <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="post" action="{{ route('admin.login.store') }}" class="mt-6 space-y-4 max-w-md">
                    @csrf
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Email</label>
                        <input
                            type="email"
                            name="email"
                            value="{{ old('email') }}"
                            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-slate-400"
                            placeholder="admin@kijumbe.local"
                            required
                        >
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Password</label>
                        <input
                            type="password"
                            name="password"
                            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-slate-400"
                            placeholder="Your password"
                            required
                        >
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="remember" value="1" class="rounded border-slate-300">
                        Remember me
                    </label>
                    <button class="w-full rounded-2xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                        Sign in
                    </button>
                </form>
            </section>
        </div>
    </div>
</body>
</html>
