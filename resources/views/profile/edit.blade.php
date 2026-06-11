<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-2xl bg-gradient-to-r from-white to-white/60 bg-clip-text text-transparent leading-tight">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">
            <div class="glass-card rounded-2xl p-8 relative overflow-hidden">
                <div class="absolute -right-20 -top-20 w-64 h-64 bg-brand-500/10 rounded-full blur-3xl pointer-events-none"></div>
                <div class="max-w-xl relative z-10">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="glass-card rounded-2xl p-8 relative overflow-hidden">
                <div class="absolute -left-20 -bottom-20 w-64 h-64 bg-brand-500/10 rounded-full blur-3xl pointer-events-none"></div>
                <div class="max-w-xl relative z-10">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            <div class="glass-card rounded-2xl p-8 border-red-500/20 relative overflow-hidden">
                <div class="max-w-xl relative z-10">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
