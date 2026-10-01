<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ name: '', password: '' });

function submit(): void {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Вход" />

    <div class="flex min-h-screen items-center justify-center bg-gray-50 p-4 text-gray-900">
        <form
            class="w-full max-w-sm space-y-4 rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
            @submit.prevent="submit"
        >
            <h1 class="text-lg font-semibold">Вход для операторов</h1>

            <div>
                <label for="name" class="mb-1 block text-sm font-medium">Имя</label>
                <input
                    id="name"
                    v-model="form.name"
                    v-focus
                    type="text"
                    autocomplete="username"
                    required
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                />
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium">Пароль</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                />
            </div>

            <p v-if="form.errors.name" class="text-sm text-red-600" role="alert">
                {{ form.errors.name }}
            </p>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
            >
                Войти
            </button>
        </form>
    </div>
</template>
