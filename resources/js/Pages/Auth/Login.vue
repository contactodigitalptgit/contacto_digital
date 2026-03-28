<script setup lang="ts">
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    canResetPassword?: boolean;
    status?: string;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Entrar" />

        <div class="auth-header">
            <p class="auth-eyebrow">Acesso seguro</p>
            <h1 class="auth-title">Entrar na plataforma</h1>
            <p class="auth-subtitle">
                Use o seu email e senha para continuar no Contacto Digital.
            </p>
        </div>

        <div v-if="status" class="auth-status">
            {{ status }}
        </div>

        <form class="auth-form" @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Email" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-2 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                    placeholder="seuemail@empresa.com"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" value="Senha" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-2 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    placeholder="Digite a sua senha"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="block">
                <label class="auth-checkbox-row">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="auth-checkbox-text">Manter sessão iniciada</span>
                </label>
            </div>

            <div class="auth-actions">
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="auth-link"
                >
                    Esqueceu a sua senha?
                </Link>

                <PrimaryButton
                    class="auth-submit sm:ms-auto"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Entrar
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
