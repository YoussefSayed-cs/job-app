<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <div class="text-center mb-8">
        <h2 class="font-heading text-2xl font-bold text-white">Welcome back</h2>
        <p class="text-slate-400 text-sm mt-2">Please enter your details to sign in.</p>
    </div>

    <form x-data="{ isSubmitting: false }" @submit="isSubmitting = true" method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <!-- Email Address -->
        <div>
            <label for="email" class="block text-sm font-medium text-slate-300 mb-1.5">{{ __('Email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" 
                class="w-full px-4 py-3 bg-slate-800/50 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition-all shadow-sm">
            <x-input-error :messages="$errors->get('email')" class="mt-2 text-red-400" />
        </div>

        <!-- Password -->
        <div>
            <label for="password" class="block text-sm font-medium text-slate-300 mb-1.5">{{ __('Password') }}</label>
            <input id="password" type="password" name="password" required autocomplete="current-password" 
                class="w-full px-4 py-3 bg-slate-800/50 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition-all shadow-sm">
            <x-input-error :messages="$errors->get('password')" class="mt-2 text-red-400" />
        </div>

        <!-- Remember Me & Forgot Password -->
        <div class="flex items-center justify-between mt-4">
            <label for="remember_me" class="inline-flex items-center cursor-pointer group">
                <div class="relative flex items-center">
                    <input id="remember_me" type="checkbox" class="peer sr-only" name="remember">
                    <div class="w-5 h-5 bg-slate-800 border border-slate-600 rounded peer-checked:bg-brand-500 peer-checked:border-brand-500 transition-colors flex items-center justify-center group-hover:border-slate-500">
                        <svg class="w-3 h-3 text-white opacity-0 peer-checked:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                    </div>
                </div>
                <span class="ms-2 text-sm text-slate-400 group-hover:text-slate-300 transition-colors">{{ __('Remember me') }}</span>
            </label>

            @if (Route::has('password.request'))
                <a class="text-sm text-brand-400 hover:text-brand-300 transition-colors" href="{{ route('password.request') }}">
                    {{ __('Forgot password?') }}
                </a>
            @endif
        </div>

        <div class="pt-2">
            <button type="submit" 
                    :disabled="isSubmitting" 
                    :class="{ 'opacity-70 cursor-not-allowed': isSubmitting, 'hover:-translate-y-0.5 hover:bg-brand-500': !isSubmitting }" 
                    class="w-full flex justify-center py-3.5 px-4 border border-transparent rounded-xl shadow-lg shadow-brand-500/20 text-sm font-bold text-white bg-brand-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-500 focus:ring-offset-slate-900 transition-all">
                <span x-show="!isSubmitting">{{ __('Log in') }}</span>
                <span x-show="isSubmitting" class="flex items-center gap-2" style="display: none;">
                    <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    {{ __('Logging in...') }}
                </span>
            </button>
        </div>

        <p class="text-center text-sm text-slate-400 mt-6">
            {{ __('Don\'t have an account?') }}
            <a href="{{ route('register') }}" class="font-medium text-brand-400 hover:text-brand-300 transition-colors">
                {{ __('Sign up') }}
            </a>
        </p>
    </form>
</x-guest-layout>
